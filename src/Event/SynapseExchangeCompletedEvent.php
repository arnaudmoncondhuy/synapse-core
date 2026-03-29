<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement de bas niveau déclenché après la complétion d'un échange LLM.
 *
 * Principalement utilisé pour le système de logging de debug interne. Il contient
 * toutes les métadonnées techniques brutes, y compris les payloads API complets
 * si le mode debug est activé.
 *
 * @see DebugLogSubscriber
 */
class SynapseExchangeCompletedEvent extends Event
{
    /**
     * @param array<int, array<string, mixed>> $safety
     * @param array<string, mixed> $rawData
     * @param array<string, mixed> $timings
     */
    public function __construct(
        private string $debugId,
        private string $model,
        private string $provider,
        private TokenUsage $usage = new TokenUsage(),
        private array $safety = [],
        private bool $debugMode = false,
        private array $rawData = [],
        private array $timings = [],
    ) {
    }

    public function getDebugId(): string
    {
        return $this->debugId;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getUsage(): TokenUsage
    {
        return $this->usage;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSafety(): array
    {
        return $this->safety;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTimings(): array
    {
        return $this->timings;
    }
}
