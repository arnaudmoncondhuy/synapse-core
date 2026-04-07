<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché AVANT l'exécution d'un outil demandé par le LLM.
 *
 * Permet au frontend d'afficher un indicateur "en cours" dans le panneau
 * de transparence pendant que l'outil s'exécute.
 */
final class SynapseToolCallStartedEvent extends Event
{
    /**
     * @param string $toolName Nom technique de l'outil à exécuter
     * @param string $toolLabel Libellé lisible de l'outil (pour l'UI)
     * @param array<string, mixed> $arguments Arguments décodés de l'appel
     * @param string $toolCallId Identifiant unique de l'appel
     * @param int $turn Index du tour multi-turn
     */
    public function __construct(
        public readonly string $toolName,
        public readonly string $toolLabel,
        public readonly array $arguments,
        public readonly string $toolCallId,
        public readonly int $turn,
    ) {
    }
}
