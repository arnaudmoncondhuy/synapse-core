<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché après la recherche de mémoire sémantique, avant l'injection dans le prompt.
 *
 * Permet au frontend d'afficher les souvenirs rappelés dans le panneau de transparence.
 */
final class SynapseMemoryResultsEvent extends Event
{
    /**
     * @param list<array{score: float, content_preview: string}> $memories Mémoires rappelées avec score et aperçu
     * @param int $totalRecalled Nombre de mémoires injectées
     */
    public function __construct(
        public readonly array $memories,
        public readonly int $totalRecalled,
    ) {
    }
}
