<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseWorkflowStepCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseWorkflowStepStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Moteur d'exécution séquentiel d'un {@see SynapseWorkflow} (Phase 8).
 *
 * `MultiAgent` implémente {@see AgentInterface} pour respecter l'alignement terminologique
 * `symfony/ai` : un workflow **est** un agent — il prend un {@see Input}, retourne un
 * {@see Output}, et peut à son tour être appelé depuis un autre contexte d'agent.
 *
 * ## Responsabilités
 *
 * 1. Valider au runtime que tous les agents référencés par les steps sont résolvables
 *    via {@see AgentResolver::has()}.
 * 2. Pour chaque step (exécution strictement séquentielle) :
 *    - construire l'input à partir de `input_mapping` et de l'état accumulé ;
 *    - créer un contexte enfant ({@see AgentContext::createChild()}) enrichi avec le
 *      `workflowRunId` du run, pour que tous les {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog}
 *      produits remontent dans l'arbre du workflow ;
 *    - appeler l'agent résolu et stocker son output ;
 *    - mettre à jour `currentStepIndex` + `totalTokens` sur le run.
 * 3. Appliquer les expressions `outputs` finales pour construire le {@see Output::$data}
 *    final retourné à l'appelant.
 *
 * ## Responsabilités déléguées (hors scope)
 *
 * - **Persistance du run** : déléguée à {@see WorkflowRunner}. `MultiAgent` mute le
 *   `SynapseWorkflowRun` en mémoire mais ne flush jamais.
 * - **Async / Messenger** : Phase 9.
 * - **Parallélisme, DAG, handoff conditionnel** : Phase 10+.
 * - **Pricing** : `totalCost` reste à `null` — le calcul de coût unifié vit dans
 *   {@see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService} et n'est pas répliqué ici
 *   (single source of truth).
 */
final class MultiAgent implements AgentInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SynapseWorkflow $workflow,
        private readonly SynapseWorkflowRun $run,
        private readonly AgentResolver $resolver,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function getName(): string
    {
        return 'workflow:'.$this->workflow->getWorkflowKey();
    }

    public function getLabel(): string
    {
        return $this->workflow->getName();
    }

    public function getDescription(): string
    {
        return $this->workflow->getDescription() ?? $this->workflow->getName();
    }

    /**
     * Exécute le workflow de bout en bout.
     *
     * Le run reçu en constructeur est muté au fil des steps (status, currentStepIndex,
     * totalTokens, errorMessage, completedAt). Aucun `flush` n'est appelé — c'est la
     * responsabilité du {@see WorkflowRunner}.
     *
     * @param Input $input Inputs initiaux du workflow. La clé `structured` est utilisée
     *                     en priorité pour alimenter `$state['inputs']` ; si elle est vide,
     *                     le message texte est posé sous `$state['inputs']['message']`.
     * @param array<string, mixed> $options peut contenir `'context' => AgentContext` (sinon
     *                                      un contexte racine est créé via le resolver)
     *
     * @throws WorkflowExecutionException si la définition est invalide ou si un step échoue
     */
    public function call(Input $input, array $options = []): Output
    {
        $definition = $this->workflow->getDefinition();
        $steps = $this->extractSteps($definition);

        // Pré-validation : tous les agents référencés doivent être résolvables.
        $this->preValidateAgents($steps);

        // Contexte parent : soit fourni par le caller, soit nouveau contexte racine.
        $parentContext = $options['context'] ?? null;
        if (!$parentContext instanceof AgentContext) {
            $parentContext = $this->resolver->createRootContext(
                userId: $this->run->getUserId(),
                origin: 'workflow',
            );
        }
        // Enrichir avec le workflowRunId du run courant (tous les enfants hériteront via createChild).
        $parentContext = $parentContext->withWorkflowRunId($this->run->getWorkflowRunId());

        // State accumulé : inputs initiaux + outputs de chaque step au fur et à mesure.
        $state = [
            'inputs' => $this->buildInitialInputs($input),
            'steps' => [],
        ];

        $this->run->setStatus(WorkflowRunStatus::RUNNING);
        $this->run->setInput($state['inputs']);

        $totalPromptTokens = 0;
        $totalCompletionTokens = 0;

        foreach ($steps as $index => $step) {
            $stepName = (string) $step['name'];
            $agentName = (string) $step['agent_name'];
            $this->run->setCurrentStepIndex($index);

            // Dispatch step started event — la sidebar affiche immédiatement que ce step réfléchit.
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new SynapseWorkflowStepStartedEvent(
                    workflowRunId: $this->run->getWorkflowRunId(),
                    workflowKey: $this->workflow->getWorkflowKey(),
                    stepIndex: $index,
                    stepName: $stepName,
                    agentName: $agentName,
                    totalSteps: \count($steps),
                ));
            }

            try {
                $stepInput = $this->resolveStepInput($step, $state);
                $childContext = $parentContext->createChild(
                    parentRunId: $parentContext->getRequestId(),
                    childOrigin: 'workflow',
                );

                $agent = $this->resolver->resolve($agentName, $childContext);
                $stepOutput = $agent->call(
                    Input::ofStructured($stepInput),
                    ['context' => $childContext],
                );
            } catch (WorkflowExecutionException $e) {
                $this->markFailed($e->getMessage());
                throw $e;
            } catch (\Throwable $e) {
                $this->logger->error('Workflow step "{step}" raised: {error}', [
                    'step' => $stepName,
                    'error' => $e->getMessage(),
                    'workflow_run_id' => $this->run->getWorkflowRunId(),
                ]);
                $this->markFailed(sprintf('Step "%s": %s', $stepName, $e->getMessage()));
                throw WorkflowExecutionException::stepFailed($stepName, $e->getMessage(), $e);
            }

            // Stocker l'output du step sous `$state['steps'][NAME]['output']`.
            $state['steps'][$stepName] = [
                'output' => [
                    'text' => $stepOutput->getAnswer(),
                    'data' => $stepOutput->getData(),
                    'generated_attachments' => $stepOutput->getGeneratedAttachments(),
                ],
            ];

            // Agrégation des usages — cohérent avec TokenUsage keys.
            $usage = $stepOutput->getUsage();
            if (isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])) {
                $totalPromptTokens += $usage['prompt_tokens'];
            }
            if (isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])) {
                $totalCompletionTokens += $usage['completion_tokens'];
            }

            // Dispatch step completed event pour le streaming sidebar "réflexion interne".
            if (null !== $this->eventDispatcher) {
                $this->eventDispatcher->dispatch(new SynapseWorkflowStepCompletedEvent(
                    workflowRunId: $this->run->getWorkflowRunId(),
                    workflowKey: $this->workflow->getWorkflowKey(),
                    stepIndex: $index,
                    stepName: $stepName,
                    agentName: $agentName,
                    answer: $stepOutput->getAnswer(),
                    usage: $usage,
                    totalSteps: \count($steps),
                ));
            }
        }

        // Tous les steps sont passés — avancer l'index au-delà du dernier et finaliser.
        $this->run->setCurrentStepIndex(count($steps));
        $this->run->setTotalTokens($totalPromptTokens + $totalCompletionTokens);

        // Construire l'output final à partir de la clause `outputs` de la définition.
        $finalOutputs = $this->resolveFinalOutputs($definition, $state);

        $this->run->setStatus(WorkflowRunStatus::COMPLETED);
        $this->run->setOutput($finalOutputs);
        $this->run->setCompletedAt(new \DateTimeImmutable());

        // Construire la réponse textuelle : concaténation des outputs non-vides.
        // Quand un seul output est non-vide, il devient la réponse directe (pas de séparateur).
        $textParts = [];
        foreach ($finalOutputs as $value) {
            if (is_string($value) && '' !== trim($value)) {
                $textParts[] = $value;
            }
        }
        $answer = implode("\n\n---\n\n", $textParts);

        // Agréger les generated_attachments de tous les steps (images, fichiers).
        $allAttachments = [];
        foreach ($state['steps'] as $stepData) {
            /** @var array{output: array{text: string|null, data: array<string, mixed>, generated_attachments: array<int, array<string, mixed>>}} $stepData */
            $stepAttachments = $stepData['output']['generated_attachments'];
            array_push($allAttachments, ...$stepAttachments);
        }

        return new Output(
            answer: '' !== $answer ? $answer : null,
            data: $finalOutputs,
            usage: [
                'prompt_tokens' => $totalPromptTokens,
                'completion_tokens' => $totalCompletionTokens,
                'total_tokens' => $totalPromptTokens + $totalCompletionTokens,
            ],
            generatedAttachments: $allAttachments,
            metadata: [
                'workflow_key' => $this->workflow->getWorkflowKey(),
                'workflow_version' => $this->run->getWorkflowVersion(),
                'workflow_run_id' => $this->run->getWorkflowRunId(),
                'steps_executed' => count($steps),
            ],
        );
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<int, array<string, mixed>>
     */
    private function extractSteps(array $definition): array
    {
        $steps = $definition['steps'] ?? null;
        if (!is_array($steps) || [] === $steps) {
            throw WorkflowExecutionException::invalidDefinition('steps array is missing or empty');
        }

        $normalized = [];
        foreach ($steps as $index => $step) {
            if (!is_array($step)) {
                throw WorkflowExecutionException::invalidDefinition(sprintf('step at index %s is not an object', (string) $index));
            }
            if (!isset($step['name']) || !is_string($step['name']) || '' === $step['name']) {
                throw WorkflowExecutionException::invalidDefinition(sprintf('step at index %s has no name', (string) $index));
            }
            if (!isset($step['agent_name']) || !is_string($step['agent_name']) || '' === $step['agent_name']) {
                throw WorkflowExecutionException::invalidDefinition(sprintf('step "%s" has no agent_name', $step['name']));
            }
            $normalized[] = $step;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $steps
     */
    private function preValidateAgents(array $steps): void
    {
        foreach ($steps as $step) {
            /** @var string $agentName */
            $agentName = $step['agent_name'];
            if (!$this->resolver->has($agentName)) {
                throw WorkflowExecutionException::agentNotResolvable((string) $step['name'], $agentName);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInitialInputs(Input $input): array
    {
        $structured = $input->getStructured();
        if ([] !== $structured) {
            return $structured;
        }

        return ['message' => $input->getMessage()];
    }

    /**
     * Construit l'input à passer à un step en résolvant son `input_mapping`.
     *
     * @param array<string, mixed> $step
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function resolveStepInput(array $step, array $state): array
    {
        $mapping = $step['input_mapping'] ?? null;
        if (!is_array($mapping)) {
            return [];
        }

        $resolved = [];
        foreach ($mapping as $key => $expression) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($expression) && JsonPathLite::isExpression($expression)) {
                $resolved[$key] = JsonPathLite::evaluate($state, $expression);
            } else {
                // Valeur littérale (string/int/bool/array/null)
                $resolved[$key] = $expression;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function resolveFinalOutputs(array $definition, array $state): array
    {
        $outputs = $definition['outputs'] ?? null;
        if (!is_array($outputs)) {
            return [];
        }

        $resolved = [];
        foreach ($outputs as $key => $expression) {
            if (!is_string($key)) {
                continue;
            }
            if (is_string($expression) && JsonPathLite::isExpression($expression)) {
                $resolved[$key] = JsonPathLite::evaluate($state, $expression);
            } else {
                $resolved[$key] = $expression;
            }
        }

        return $resolved;
    }

    private function markFailed(string $reason): void
    {
        $this->run->setStatus(WorkflowRunStatus::FAILED);
        $this->run->setErrorMessage($reason);
        $this->run->setCompletedAt(new \DateTimeImmutable());
    }
}
