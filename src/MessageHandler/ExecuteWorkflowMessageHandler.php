<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\MessageHandler;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Message\ExecuteWorkflowMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Handler Messenger pour {@see ExecuteWorkflowMessage} (Phase 9).
 *
 * Exécute un workflow de manière asynchrone en déléguant à {@see WorkflowRunner}.
 *
 * ## Erreurs et retries
 *
 * - **Workflow introuvable ou désactivé** : `UnrecoverableMessageHandlingException`
 *   → pas de retry (aucune chance de succès sans intervention admin). Le message
 *   part en failed transport.
 * - **Échec d'un step** ({@see WorkflowExecutionException}) : le run est déjà persisté
 *   avec `status=FAILED` par `WorkflowRunner`. On log et on rethrow une
 *   `UnrecoverableMessageHandlingException` pour éviter une cascade de retries
 *   dupliquant des `SynapseWorkflowRun` en échec (chaque retry crée un nouveau run).
 * - **Erreur infrastructurelle transitoire** (ex: Doctrine flush, LLM timeout remonté
 *   autrement) : laisser remonter l'exception originale pour que Messenger applique
 *   sa stratégie de retry par défaut.
 *
 * Cette politique est volontairement conservatrice pour le MVP Phase 9. Une
 * distinction fine (retryable vs non-retryable par type d'erreur) viendra si
 * les premiers cas de terrain le justifient.
 */
#[AsMessageHandler]
final class ExecuteWorkflowMessageHandler
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SynapseWorkflowRepository $workflowRepository,
        private readonly WorkflowRunner $workflowRunner,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function __invoke(ExecuteWorkflowMessage $message): void
    {
        $workflow = $this->workflowRepository->findActiveByKey($message->getWorkflowKey());
        if (null === $workflow) {
            $this->logger->warning('ExecuteWorkflowMessage received for unknown or inactive workflow "{key}"', [
                'key' => $message->getWorkflowKey(),
            ]);

            throw new UnrecoverableMessageHandlingException(sprintf('Workflow "%s" is unknown or inactive — message dropped.', $message->getWorkflowKey()));
        }

        $structured = $message->getStructuredInput();
        $input = [] !== $structured
            ? Input::ofStructured($structured)
            : Input::ofMessage($message->getMessage());

        $options = [];
        if (null !== $message->getUserId()) {
            $options['user_id'] = $message->getUserId();
        }

        try {
            $this->workflowRunner->run($workflow, $input, $options);
        } catch (WorkflowExecutionException $e) {
            // Le run est déjà persisté en FAILED par WorkflowRunner — pas de retry.
            $this->logger->error('Workflow "{key}" failed during async execution: {error}', [
                'key' => $message->getWorkflowKey(),
                'error' => $e->getMessage(),
                'step' => $e->getStepName(),
            ]);

            throw new UnrecoverableMessageHandlingException($e->getMessage(), 0, $e);
        }
    }
}
