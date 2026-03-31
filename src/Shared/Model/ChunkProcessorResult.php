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
     * @param list<array<string, mixed>> $geminiRawParts Raw Gemini parts (thinking+functionCall) for multi-turn history
     */
    public function __construct(
        public string $modelText,
        public array $modelToolCalls,
        public TokenUsage $usage,
        public array $safetyRatings,
        public array $geminiRawParts = [],
    ) {
    }
}
