<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

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
 *     $tokens = $event->getUsage();
 *     // Enregistrer des statistiques de consommation
 * }
 * ```
 */
class SynapseGenerationCompletedEvent extends Event
{
    /**
     * @param string      $fullResponse La réponse textuelle complète générée.
     * @param array       $usage        Consommation finale des tokens (total).
     * @param string|null $debugId      ID de debug associé si le mode debug était actif.
     */
    public function __construct(
        private string $fullResponse,
        private array $usage = [],
        private ?string $debugId = null,
    ) {}

    /**
     * Retourne le texte complet de la réponse IA.
     */
    public function getFullResponse(): string
    {
        return $this->fullResponse;
    }

    /**
     * Retourne les statistiques d'usage cumulées (input, output, thinking).
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    /**
     * Retourne l'identifiant unique de debug (si disponible).
     */
    public function getDebugId(): ?string
    {
        return $this->debugId;
    }
}
