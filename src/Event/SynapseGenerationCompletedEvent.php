<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché lorsque TOUT le processus de génération est terminé.
 *
 * Contrairement à l'événement de chunk, celui-ci ne survient qu'UNE fois, après
 * que tous les appels LLM et tous les appels d'outils ont été résolus.
 * Il contient la réponse finale consolidée.
 *
 * @example
 * ```php
 * #[AsEventListener(event: SynapseGenerationCompletedEvent::class)]
 * public function onGenerationCompleted(SynapseGenerationCompletedEvent $event): void
 * {
 *     $fullText = $event->getFullResponse();
 *     $tokens   = $event->getUsage();
 * }
 * ```
 */
class SynapseGenerationCompletedEvent extends Event
{
    public function __construct(
        private string $fullResponse,
        private TokenUsage $usage = new TokenUsage(),
        private ?string $debugId = null,
    ) {
    }

    public function getFullResponse(): string
    {
        return $this->fullResponse;
    }

    public function getUsage(): TokenUsage
    {
        return $this->usage;
    }

    public function getDebugId(): ?string
    {
        return $this->debugId;
    }
}
