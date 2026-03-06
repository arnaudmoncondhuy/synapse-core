<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché à CHAQUE chunk reçu du LLM.
 *
 * Cet événement est essentiel pour les interfaces de chat en "streaming". Il permet
 * de récupérer les fragments de texte (tokens) au fur et à mesure de leur génération.
 *
 * @example
 * ```php
 * #[AsEventListener(event: SynapseChunkReceivedEvent::class)]
 * public function onChunk(SynapseChunkReceivedEvent $event): void
 * {
 *     $token = $event->getText();
 *     if ($token) {
 *         // Diffuser le token via Mercure, WebSocket ou Server-Sent Events
 *     }
 * }
 * ```
 */
class SynapseChunkReceivedEvent extends Event
{
    private int $turn;
    /** @var array<string, mixed>|null */
    private ?array $rawChunk = null;

    /**
     * @param array<string, mixed>      $chunk    Le chunk normalisé reçu (text, function_calls, usage, etc.)
     * @param int                       $turn     L'index du tour de parole actuel
     * @param array<string, mixed>|null $rawChunk Le payload brut reçu de l'API provider (pour debug avancé)
     */
    public function __construct(
        private array $chunk,
        int $turn = 0,
        ?array $rawChunk = null,
    ) {
        $this->turn = $turn;
        $this->rawChunk = $rawChunk;
    }

    /**
     * Retourne tout le contenu normalisé du chunk.
     *
     * @return array<string, mixed>
     */
    public function getChunk(): array
    {
        return $this->chunk;
    }

    /**
     * Retourne uniquement le fragment de texte généré dans ce chunk.
     */
    public function getText(): ?string
    {
        return isset($this->chunk['text']) && is_string($this->chunk['text']) ? (string) $this->chunk['text'] : null;
    }

    /**
     * Retourne les pensées internes (reasoning) si le modèle le supporte.
     */
    public function getThinking(): ?string
    {
        return isset($this->chunk['thinking']) && is_string($this->chunk['thinking']) ? (string) $this->chunk['thinking'] : null;
    }

    /**
     * Retourne les appels de fonctions demandés dans ce chunk.
     *
     * @return array<int, array{id: string, name: string, args: array<string, mixed>}>
     */
    public function getFunctionCalls(): array
    {
        /** @var array<int, array{id: string, name: string, args: array<string, mixed>}> $calls */
        $calls = is_array($this->chunk['function_calls'] ?? null) ? $this->chunk['function_calls'] : [];

        return $calls;
    }

    /**
     * Retourne les statistiques d'usage de tokens s'il s'agit du dernier chunk.
     *
     * @return array<string, mixed>
     */
    public function getUsage(): array
    {
        return is_array($this->chunk['usage'] ?? null) ? $this->chunk['usage'] : [];
    }

    /**
     * Indique si la génération a été bloquée pour des raisons de sécurité.
     */
    public function isBlocked(): bool
    {
        return (bool) ($this->chunk['blocked'] ?? false);
    }

    /**
     * Retourne l'index du tour de parole (utile en cas de multi-step tool calls).
     */
    public function getTurn(): int
    {
        return $this->turn;
    }

    /**
     * Retourne le payload brut reçu du provider (pour debug).
     *
     * @return array<string, mixed>|null
     */
    public function getRawChunk(): ?array
    {
        return $this->rawChunk;
    }
}
