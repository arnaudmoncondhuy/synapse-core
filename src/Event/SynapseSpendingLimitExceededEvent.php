<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Déclenché lorsqu'un plafond de dépense serait dépassé par une requête LLM.
 *
 * Cet événement est dispatché par {@see SpendingLimitChecker::assertCanSpend()}
 * juste avant de lever {@see \ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException}.
 *
 * Il permet à l'application hôte de :
 * - Notifier l'utilisateur (email, toast, log)
 * - Enregistrer l'incident dans un système de monitoring
 * - Effectuer un fallback vers un modèle moins coûteux
 *
 * L'exception LlmQuotaException est toujours levée après le dispatch.
 */
class SynapseSpendingLimitExceededEvent extends Event
{
    /**
     * @param string $userId Identifiant de l'utilisateur
     * @param string $scope Portée du plafond ('user', 'preset', 'agent')
     * @param string $scopeId Identifiant de la ressource soumise au plafond
     * @param SpendingLimitPeriod $period Période du plafond (jour glissant, mois, etc.)
     * @param float $limitAmount Montant du plafond configuré
     * @param float $consumption Consommation actuelle sur la période
     * @param float $estimatedCost Coût estimé de la requête bloquée
     * @param string $currency Devise du plafond (ex: EUR, USD)
     */
    public function __construct(
        private readonly string $userId,
        private readonly string $scope,
        private readonly string $scopeId,
        private readonly SpendingLimitPeriod $period,
        private readonly float $limitAmount,
        private readonly float $consumption,
        private readonly float $estimatedCost,
        private readonly string $currency,
    ) {
    }

    /**
     * Identifiant de l'utilisateur ayant déclenché la requête bloquée.
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * Portée du plafond dépassé : 'user', 'preset' ou 'agent'.
     */
    public function getScope(): string
    {
        return $this->scope;
    }

    /**
     * Identifiant de la ressource associée au plafond (userId, presetId, agentId).
     */
    public function getScopeId(): string
    {
        return $this->scopeId;
    }

    /**
     * Période de calcul du plafond.
     */
    public function getPeriod(): SpendingLimitPeriod
    {
        return $this->period;
    }

    /**
     * Montant du plafond configuré (en devise de référence).
     */
    public function getLimitAmount(): float
    {
        return $this->limitAmount;
    }

    /**
     * Consommation actuelle sur la période (en devise de référence).
     */
    public function getConsumption(): float
    {
        return $this->consumption;
    }

    /**
     * Coût estimé de la requête qui a déclenché le blocage.
     */
    public function getEstimatedCost(): float
    {
        return $this->estimatedCost;
    }

    /**
     * Devise du plafond (ex: EUR, USD).
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Montant total qui aurait été consommé si la requête avait passé.
     */
    public function getProjectedConsumption(): float
    {
        return $this->consumption + $this->estimatedCost;
    }

    /**
     * Dépassement en valeur absolue (combien au-dessus du plafond).
     */
    public function getOverrunAmount(): float
    {
        return $this->getProjectedConsumption() - $this->limitAmount;
    }
}
