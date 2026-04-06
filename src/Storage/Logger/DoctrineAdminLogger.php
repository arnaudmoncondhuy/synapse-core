<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Logger;

use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine-based implementation of SynapseDebugLoggerInterface.
 *
 * Persists only lightweight metadata to the database, avoiding storage of massive raw payloads.
 * This keeps the database performant while still providing dashboard visibility.
 *
 * Suitable for admin dashboards where you want to see historical exchange metadata without
 * storing full JSON request/response bodies.
 */
class DoctrineAdminLogger implements SynapseDebugLoggerInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private SynapseDebugLogRepository $repository,
    ) {
    }

    public function logExchange(string $debugId, array $metadata, array $rawPayload): void
    {
        $debugLog = new SynapseDebugLog();
        $debugLog->setDebugId($debugId);

        // Store the COMPLETE payload for template rendering
        // (template needs 'turns', 'system_prompt', etc.)
        // Sanitize UTF-8 to prevent serialization errors during DB storage
        $debugLog->setData(TextUtil::sanitizeArrayUtf8($rawPayload));

        if (isset($metadata['conversation_id'])) {
            $conversationId = $metadata['conversation_id'];
            $debugLog->setConversationId(is_scalar($conversationId) ? (string) $conversationId : null);
        }

        // Module/action : peuplés par DebugLogSubscriber depuis SynapseExchangeCompletedEvent
        // (source = options `module`/`action` passées à ChatService::ask()). Dénormalisation
        // alignée sur SynapseLlmCall pour pouvoir afficher la liste debug sans charger le JSON.
        $debugLog->setModule(isset($rawPayload['module']) && is_string($rawPayload['module']) ? $rawPayload['module'] : null);
        $debugLog->setAction(isset($rawPayload['action']) && is_string($rawPayload['action']) ? $rawPayload['action'] : null);
        $debugLog->setModel(isset($rawPayload['model']) && is_string($rawPayload['model']) ? $rawPayload['model'] : null);

        $usage = $rawPayload['usage'] ?? $rawPayload['token_usage'] ?? null;
        if (is_array($usage)) {
            $total = $usage['total_tokens'] ?? (($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0));
            $debugLog->setTotalTokens(is_int($total) ? $total : null);
        }

        $debugLog->setCreatedAt(new \DateTimeImmutable());

        // Agent traceability (populated if the call came from AgentResolver + AgentContext).
        if (isset($metadata['agent_run_id']) && is_string($metadata['agent_run_id'])) {
            $debugLog->setAgentRunId($metadata['agent_run_id']);
        }
        if (isset($metadata['parent_run_id']) && is_string($metadata['parent_run_id'])) {
            $debugLog->setParentRunId($metadata['parent_run_id']);
        }
        if (isset($metadata['depth']) && is_int($metadata['depth'])) {
            $debugLog->setDepth($metadata['depth']);
        }
        if (isset($metadata['origin']) && is_string($metadata['origin'])) {
            $debugLog->setOrigin($metadata['origin']);
        }
        // Workflow traceability (Phase 7) — propagé depuis AgentContext::$workflowRunId
        // via DebugLogSubscriber. NULL si l'appel n'est pas dans un workflow.
        if (isset($metadata['workflow_run_id']) && is_string($metadata['workflow_run_id'])) {
            $debugLog->setWorkflowRunId($metadata['workflow_run_id']);
        }

        $this->em->persist($debugLog);
        $this->em->flush();
    }

    public function findByDebugId(string $debugId): ?array
    {
        $debugLog = $this->repository->findByDebugId($debugId);

        if (!$debugLog) {
            return null;
        }

        return [
            'debug_id' => $debugLog->getDebugId(),
            'conversation_id' => $debugLog->getConversationId(),
            'created_at' => $debugLog->getCreatedAt(),
            'data' => $debugLog->getData(),
        ];
    }
}
