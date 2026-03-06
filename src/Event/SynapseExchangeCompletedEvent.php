<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

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
     * @param string               $debugId   identifiant unique de cet échange précis
     * @param string               $model     Modèle technique utilisé (ex: 'gemini-1.5-flash').
     * @param string               $provider  nom du client provider (ex: 'gemini')
     * @param array<string, mixed> $usage     détails de consommation
     * @param array<string, mixed> $safety    évaluations de sécurité
     * @param bool                 $debugMode indique si le mode debug était activé par l'utilisateur
     * @param array<string, mixed> $rawData   données brutes de la requête et de la réponse (payloads)
     * @param array<string, mixed> $timings   données chronométriques des étapes d'exécution (en millisecondes)
     */
    public function __construct(
        private string $debugId,
        private string $model,
        private string $provider,
        private array $usage = [],
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

    /**
     * @return array<string, mixed>
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    /**
     * @return array<string, mixed>
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
     * Retourne les données API brutes (Requêtes + Réponses).
     *
     * @return array<string, mixed>
     */
    public function getRawData(): array
    {
        return $this->rawData;
    }

    /**
     * Retourne les étapes temporelles du cycle d'exécution (ms).
     *
     * @return array<string, mixed>
     */
    public function getTimings(): array
    {
        return $this->timings;
    }
}
