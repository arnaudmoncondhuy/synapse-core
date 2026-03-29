<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché quand un composant bascule sur sa configuration de secours (fallback).
 *
 * Le dashboard admin peut écouter cet événement pour signaler
 * que la configuration active n'est pas optimale.
 */
final class SynapseFallbackActivatedEvent extends Event
{
    public function __construct(
        /** Composant concerné : 'config', 'rag', 'memory', 'tool' */
        public readonly string $component,
        /** Raison humaine-lisible du fallback */
        public readonly string $reason,
        public readonly ?\Throwable $exception = null,
    ) {
    }
}
