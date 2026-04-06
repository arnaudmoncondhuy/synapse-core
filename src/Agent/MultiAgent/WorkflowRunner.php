<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Point d'entrée de service pour l'exécution synchrone d'un {@see SynapseWorkflow} (Phase 8).
 *
 * `WorkflowRunner` complète {@see MultiAgent} en prenant en charge tout ce qui relève
 * de la persistance et du cycle de vie du {@see SynapseWorkflowRun} :
 *
 * 1. Crée une nouvelle instance de `SynapseWorkflowRun`, dénormalise `workflowKey` /
 *    `workflowVersion` / `stepsCount` via `setWorkflow()`, et la persiste en base
 *    (statut initial `PENDING`).
 * 2. Instancie un {@see MultiAgent} avec le workflow et le run, puis délègue l'exécution
 *    via `call()`. `MultiAgent` mute le run en RAM (status, currentStepIndex, totalTokens,
 *    errorMessage, completedAt) mais ne flush jamais.
 * 3. En succès comme en échec, flush les mutations — le run trace toujours l'issue de
 *    l'exécution (COMPLETED ou FAILED).
 * 4. Retourne l'{@see Output} produit par le `MultiAgent` à l'appelant.
 *
 * ## Responsabilités laissées au caller
 *
 * - Garantir que la définition du workflow est valide et exécutable (Phase 7 a livré
 *   la validation minimale côté admin ; les erreurs d'agent_name inconnu, elles,
 *   remontent depuis `MultiAgent::preValidateAgents()`).
 * - Passer un `AgentContext` via `$options['context']` s'il est déjà dans un arbre
 *   d'agents. Sinon, `MultiAgent` créera un contexte racine via `AgentResolver`.
 * - Capturer l'{@see WorkflowExecutionException} si le caller a besoin d'un traitement
 *   spécifique (UI admin, retry, notification). Le runner se contente de rethrow
 *   après avoir flushé.
 *
 * ## Async / Messenger
 *
 * `WorkflowRunner` est **synchrone**. L'exécution asynchrone via Messenger (Phase 9)
 * réutilisera ce service en l'appelant depuis un message handler.
 */
class WorkflowRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AgentResolver $resolver,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Lance un workflow de manière synchrone et retourne son {@see Output} final.
     *
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     *
     * @throws WorkflowExecutionException Si la définition est invalide ou si un step échoue.
     *                                    Dans tous les cas, le `SynapseWorkflowRun` est flushé
     *                                    avec le statut final (FAILED) et l'errorMessage.
     */
    public function run(SynapseWorkflow $workflow, Input $input, array $options = []): Output
    {
        $run = $this->createRun($workflow, $options);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        $this->logger->info('Workflow run {workflowRunId} started for workflow "{workflowKey}" (version {version})', [
            'workflowRunId' => $run->getWorkflowRunId(),
            'workflowKey' => $run->getWorkflowKey(),
            'version' => $run->getWorkflowVersion(),
        ]);

        $multiAgent = new MultiAgent($workflow, $run, $this->resolver, $this->eventDispatcher, $this->logger);

        try {
            $output = $multiAgent->call($input, $options);
        } catch (\Throwable $e) {
            // MultiAgent a déjà positionné status=FAILED + errorMessage + completedAt.
            // On flush quand même pour persister l'échec, puis on rethrow.
            if (WorkflowRunStatus::FAILED !== $run->getStatus()) {
                // Ceinture + bretelles : MultiAgent est censé avoir fait markFailed(),
                // mais si une exception inattendue (avant ou après les catches internes)
                // remonte, on garantit quand même l'état cohérent.
                $run->setStatus(WorkflowRunStatus::FAILED);
                $run->setErrorMessage($e->getMessage());
                $run->setCompletedAt(new \DateTimeImmutable());
            }
            $this->entityManager->flush();

            throw $e;
        }

        $this->entityManager->flush();

        $this->logger->info('Workflow run {workflowRunId} completed in {duration}s with status {status}', [
            'workflowRunId' => $run->getWorkflowRunId(),
            'duration' => $run->getDurationSeconds(),
            'status' => $run->getStatus()->value,
        ]);

        return $output;
    }

    /**
     * @param array{context?: \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext, user_id?: string|null} $options
     */
    private function createRun(SynapseWorkflow $workflow, array $options): SynapseWorkflowRun
    {
        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow); // dénormalise workflowKey, workflowVersion, stepsCount

        // userId : priorité à l'option explicite, sinon au contexte fourni, sinon null.
        if (isset($options['user_id']) && is_string($options['user_id'])) {
            $run->setUserId($options['user_id']);
        } elseif (isset($options['context'])) {
            $context = $options['context'];
            if ($context instanceof \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext) {
                $run->setUserId($context->getUserId());
            }
        }

        return $run;
    }
}
