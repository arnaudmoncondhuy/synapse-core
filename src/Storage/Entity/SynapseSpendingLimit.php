<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Plafond de dépense (tokens / coût) par utilisateur ou par preset.
 *
 * Les montants sont en devise de référence (synapse.token_tracking.reference_currency).
 * La consommation est comparée à ce plafond sur la fenêtre temporelle définie.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_spending_limit')]
class SynapseSpendingLimit
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** user | preset */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: SpendingLimitScope::class)]
    private SpendingLimitScope $scope;

    /** ID utilisateur (si scope=user) ou ID preset (si scope=preset) */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $scopeId = '';

    /** Montant maximum en devise de référence sur la fenêtre */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $amount = '0.000000';

    /** Devise (doit être la devise de référence pour les plafonds) */
    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency = 'EUR';

    /** Fenêtre temporelle */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: SpendingLimitPeriod::class)]
    private SpendingLimitPeriod $period;

    /** Nom optionnel pour l'admin */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getScope(): SpendingLimitScope
    {
        return $this->scope;
    }

    public function setScope(SpendingLimitScope $scope): self
    {
        $this->scope = $scope;
        return $this;
    }

    public function getScopeId(): string
    {
        return $this->scopeId;
    }

    public function setScopeId(string $scopeId): self
    {
        $this->scopeId = $scopeId;
        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;
        return $this;
    }

    public function getPeriod(): SpendingLimitPeriod
    {
        return $this->period;
    }

    public function setPeriod(SpendingLimitPeriod $period): self
    {
        $this->period = $period;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
