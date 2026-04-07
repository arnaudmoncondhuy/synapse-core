<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché à la fin de chaque tour multi-turn (après exécution des outils, avant le re-call LLM).
 *
 * Permet au frontend d'afficher la progression des tours dans le panneau de transparence,
 * avec un résumé des outils appelés et des tokens consommés par tour.
 */
final class SynapseMultiTurnIterationEvent extends Event
{
    /**
     * @param int $turn Index du tour (0-based)
     * @param int $maxTurns Nombre maximum de tours autorisés
     * @param list<array{name: string, args_summary: string}> $toolCallsSummary Résumé des outils appelés
     * @param array<string, int> $usage Tokens consommés sur ce tour
     * @param bool $hasFurtherTools True si des outils ont été exécutés
     */
    public function __construct(
        public readonly int $turn,
        public readonly int $maxTurns,
        public readonly array $toolCallsSummary,
        public readonly array $usage,
        public readonly bool $hasFurtherTools,
    ) {
    }
}
