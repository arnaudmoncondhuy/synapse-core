<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catalogue des modèles LLM disponibles dans Synapse.
 *
 * Chaque modèle est lié à un provider par son `providerName` (slug).
 * Les capacités techniques (thinking, topK, etc.) restent dans ModelCapabilityRegistry (code).
 * Cette entité gère la visibilité et le pricing, administrables depuis l'UI.
 *
 * Pré-peuplé au premier démarrage via SynapseSetupCommand ou migration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_model')]
class SynapseModel
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Slug du provider auquel appartient ce modèle.
     * Ex : 'gemini', 'ovh'
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $providerName = '';

    /**
     * Identifiant exact du modèle tel qu'envoyé à l'API.
     * Ex : 'gemini-2.5-flash', 'Gpt-oss-20b'
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $modelId = '';

    /**
     * Nom affiché dans l'admin.
     * Ex : 'Gemini 2.5 Flash (Thinking)', 'GPT OSS 20B'
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $label = '';

    /**
     * Activer/désactiver ce modèle dans l'admin (dropdown presets).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    /**
     * Coût d'entrée en $/1M tokens (null = non renseigné).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pricingInput = null;

    /**
     * Coût de sortie en $/1M tokens (null = non renseigné).
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $pricingOutput = null;

    /**
     * Ordre d'affichage dans l'admin (plus petit = en premier).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function setProviderName(string $providerName): self
    {
        $this->providerName = $providerName;
        return $this;
    }

    public function getModelId(): string
    {
        return $this->modelId;
    }

    public function setModelId(string $modelId): self
    {
        $this->modelId = $modelId;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;
        return $this;
    }

    public function getPricingInput(): ?float
    {
        return $this->pricingInput;
    }

    public function setPricingInput(?float $pricingInput): self
    {
        $this->pricingInput = $pricingInput;
        return $this;
    }

    public function getPricingOutput(): ?float
    {
        return $this->pricingOutput;
    }

    public function setPricingOutput(?float $pricingOutput): self
    {
        $this->pricingOutput = $pricingOutput;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }
}
