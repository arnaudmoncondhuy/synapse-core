<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché après la recherche vectorielle RAG, avant l'injection dans le prompt.
 *
 * Permet au frontend d'afficher les sources consultées avec leurs scores
 * dans le panneau de transparence.
 */
final class SynapseRagResultsEvent extends Event
{
    /**
     * @param list<array{source: string, score: float, content_preview: string}> $results Résultats RAG avec source, score et aperçu
     * @param int $totalInjected Nombre de résultats injectés dans le prompt
     * @param int $tokenEstimate Estimation du nombre de tokens du bloc RAG
     */
    public function __construct(
        public readonly array $results,
        public readonly int $totalInjected,
        public readonly int $tokenEstimate,
    ) {
    }
}
