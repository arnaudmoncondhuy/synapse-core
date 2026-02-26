<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement de bas niveau déclenché après la complétion d'un échange LLM.
 *
 * Principalement utilisé pour le système de logging de debug interne. Il contient
 * toutes les métadonnées techniques brutes, y compris les payloads API complets
 * si le mode debug est activé.
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Core\Event\DebugLogSubscriber
 */
class SynapseExchangeCompletedEvent extends Event
{
    /**
     * @param string $debugId   Identifiant unique de cet échange précis.
     * @param string $model     Modèle technique utilisé (ex: 'gemini-1.5-flash').
     * @param string $provider  Nom du client provider (ex: 'gemini').
     * @param array  $usage     Détails de consommation.
     * @param array  $safety    Évaluations de sécurité.
     * @param bool   $debugMode Indique si le mode debug était activé par l'utilisateur.
     * @param array  $rawData   Données brutes de la requête et de la réponse (payloads).
     */
    public function __construct(
        private string $debugId,
        private string $model,
        private string $provider,
        private array $usage = [],
        private array $safety = [],
        private bool $debugMode = false,
        private array $rawData = [],
    ) {}

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

    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getSafety(): array
    {
        return $this->safety;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * Retourne les données API brutes (Requêtes + Réponses).
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }
}
