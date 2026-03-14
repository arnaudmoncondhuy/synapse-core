<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des dépassements de plafond de dépense.
 *
 * Chaque entrée correspond à un événement {@see \ArnaudMoncondhuy\SynapseCore\Event\SynapseSpendingLimitExceededEvent}
 * persisté par {@see \ArnaudMoncondhuy\SynapseCore\Event\SpendingLimitExceededListener}.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_spending_limit_log')]
#[ORM\Index(columns: ['exceeded_at'], name: 'idx_ssl_exceeded_at')]
#[ORM\Index(columns: ['user_id'], name: 'idx_ssl_user_id')]
#[ORM\Index(columns: ['scope', 'scope_id'], name: 'idx_ssl_scope')]
class SynapseSpendingLimitLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $userId;

    /** user | preset | agent */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $scope;

    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $scopeId;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: SpendingLimitPeriod::class)]
    private SpendingLimitPeriod $period;

    /** Montant du plafond configuré */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $limitAmount;

    /** Consommation sur la période au moment du dépassement */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $consumption;

    /** Coût estimé de la requête bloquée */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $estimatedCost;

    /** Dépassement = consumption + estimatedCost - limitAmount */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $overrunAmount;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $exceededAt;

    public function __construct(
        string $userId,
        string $scope,
        string $scopeId,
        SpendingLimitPeriod $period,
        float $limitAmount,
        float $consumption,
        float $estimatedCost,
        float $overrunAmount,
        string $currency,
    ) {
        $this->userId = $userId;
        $this->scope = $scope;
        $this->scopeId = $scopeId;
        $this->period = $period;
        $this->limitAmount = (string) $limitAmount;
        $this->consumption = (string) $consumption;
        $this->estimatedCost = (string) $estimatedCost;
        $this->overrunAmount = (string) $overrunAmount;
        $this->currency = $currency;
        $this->exceededAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getScopeId(): string
    {
        return $this->scopeId;
    }

    public function getPeriod(): SpendingLimitPeriod
    {
        return $this->period;
    }

    public function getLimitAmount(): float
    {
        return (float) $this->limitAmount;
    }

    public function getConsumption(): float
    {
        return (float) $this->consumption;
    }

    public function getEstimatedCost(): float
    {
        return (float) $this->estimatedCost;
    }

    public function getOverrunAmount(): float
    {
        return (float) $this->overrunAmount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getExceededAt(): \DateTimeImmutable
    {
        return $this->exceededAt;
    }
}
