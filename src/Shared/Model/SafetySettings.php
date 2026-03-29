<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object pour les paramètres de sécurité/modération du LLM.
 */
final readonly class SafetySettings
{
    public function __construct(
        public bool $enabled = false,
        public string $defaultThreshold = 'BLOCK_MEDIUM_AND_ABOVE',
        /** @var array<string, string> catégorie => seuil */
        public array $thresholds = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? false),
            defaultThreshold: (string) ($data['default_threshold'] ?? $data['defaultThreshold'] ?? 'BLOCK_MEDIUM_AND_ABOVE'),
            thresholds: (array) ($data['thresholds'] ?? []),
        );
    }
}
