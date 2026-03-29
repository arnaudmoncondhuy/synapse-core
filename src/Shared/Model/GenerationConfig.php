<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object pour la configuration de génération LLM.
 */
final readonly class GenerationConfig
{
    public function __construct(
        public float $temperature = 1.0,
        public float $topP = 0.95,
        public ?int $topK = null,
        public ?int $maxOutputTokens = null,
        /** @var list<string> */
        public array $stopSequences = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            temperature: (float) ($data['temperature'] ?? 1.0),
            topP: (float) ($data['top_p'] ?? $data['topP'] ?? 0.95),
            topK: null !== ($data['top_k'] ?? $data['topK'] ?? null) ? (int) ($data['top_k'] ?? $data['topK']) : null,
            maxOutputTokens: null !== ($data['max_output_tokens'] ?? $data['maxOutputTokens'] ?? null) ? (int) ($data['max_output_tokens'] ?? $data['maxOutputTokens']) : null,
            stopSequences: array_values(array_map('strval', (array) ($data['stop_sequences'] ?? $data['stopSequences'] ?? []))),
        );
    }
}
