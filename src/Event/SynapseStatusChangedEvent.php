<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché à chaque changement d'état dans le pipeline de génération.
 *
 * Remplace le callback `$onStatusUpdate` de ChatService::ask().
 * Les consumers (API SSE, WebSocket, CLI) s'y abonnent pour afficher la progression.
 *
 * Exemples de steps :
 *   - 'thinking'       → réflexion LLM
 *   - 'tool:weather'   → exécution d'un outil
 *   - 'generating'     → génération de la réponse
 */
final class SynapseStatusChangedEvent extends Event
{
    public function __construct(
        public readonly string $message,
        public readonly string $step,
        public readonly int $turn = 0,
    ) {
    }
}
