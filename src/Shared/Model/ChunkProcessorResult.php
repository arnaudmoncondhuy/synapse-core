<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Résultat normalisé du traitement d'un itérable de chunks LLM pour un tour donné.
 */
final readonly class ChunkProcessorResult
{
    /**
     * @param list<array<string, mixed>> $modelToolCalls Tool calls en format OpenAI
     * @param array<int, array<string, mixed>> $safetyRatings
     * @param list<array<string, mixed>> $providerRawParts Raw provider parts (thinking+functionCall) for multi-turn history
     * @param list<array{mime_type: string, data: string}> $generatedImages Images générées par le LLM dans ce tour
     */
    public function __construct(
        public string $modelText,
        public array $modelToolCalls,
        public TokenUsage $usage,
        public array $safetyRatings,
        public array $providerRawParts = [],
        public array $generatedImages = [],
    ) {
    }
}
