<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object pour la configuration du mode "thinking" (raisonnement étendu).
 */
final readonly class ThinkingConfig
{
    public const DEFAULT_BUDGET = 1024;

    public function __construct(
        public bool $enabled = false,
        public int $budget = self::DEFAULT_BUDGET,
        public string $reasoningEffort = 'high',
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            budget: (int) ($data['budget'] ?? $data['thinking_budget'] ?? self::DEFAULT_BUDGET),
            reasoningEffort: (string) ($data['reasoning_effort'] ?? $data['reasoningEffort'] ?? 'high'),
        );
    }
}
