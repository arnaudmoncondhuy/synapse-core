<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Logger;

use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use Psr\Log\LoggerInterface;

/**
 * Monolog-based implementation of SynapseDebugLoggerInterface.
 *
 * Persists debug logs to file-based Monolog channels.
 * Suitable for production environments where database storage of large payloads is not desired.
 */
class MonologDebugLogger implements SynapseDebugLoggerInterface
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function logExchange(string $debugId, array $metadata, array $rawPayload): void
    {
        $logData = array_merge(['debug_id' => $debugId], $metadata, ['payload' => $rawPayload]);

        $this->logger->info('Synapse LLM Exchange', $logData);
    }

    public function findByDebugId(string $debugId): ?array
    {
        // Monolog doesn't provide built-in retrieval by ID.
        // To implement this, you would need to:
        // 1. Parse log files and search for the debugId
        // 2. Use a logging aggregation service (e.g., ELK, Loki)
        // 3. Fall back to DoctrineAdminLogger for retrieval
        //
        // For now, return null to indicate this implementation doesn't support retrieval.
        return null;
    }
}
