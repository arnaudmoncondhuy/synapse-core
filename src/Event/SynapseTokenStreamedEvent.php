<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché à chaque token texte reçu du LLM en mode streaming.
 *
 * Remplace le callback `$onToken` de ChatService::ask().
 * Les consumers (API SSE, WebSocket, CLI) s'y abonnent pour streamer les tokens.
 */
final class SynapseTokenStreamedEvent extends Event
{
    public function __construct(
        public readonly string $token,
        public readonly int $turn = 0,
    ) {
    }
}
