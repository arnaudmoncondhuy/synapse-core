<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Résultat consolidé de la boucle multi-tours LLM (tool calling inclus).
 */
final readonly class MultiTurnResult
{
    /**
     * @param array<int, array<string, mixed>> $safetyRatings
     * @param array<string, mixed> $rawData Données brutes (request body, API chunks) pour debug
     * @param list<array{mime_type: string, data: string}> $generatedAttachments Fichiers générés par le LLM
     * @param array<string, mixed>|null $structuredData Contenu JSON parsé quand `response_format` est activé,
     *                                                  null sinon. Peuplé par {@see \ArnaudMoncondhuy\SynapseCore\Engine\MultiTurnExecutor}.
     */
    public function __construct(
        public string $fullText,
        public TokenUsage $usage,
        public array $safetyRatings,
        public array $rawData,
        public array $generatedAttachments = [],
        public ?array $structuredData = null,
    ) {
    }
}
