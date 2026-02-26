<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Provider LLM enregistré dans Synapse.
 *
 * Stocke les credentials par provider (API key, service account JSON, endpoint...).
 * Les credentials sont opaques (JSON) — chaque provider gère sa propre structure.
 *
 * Cette entité est gérée depuis l'admin Synapse — aucune valeur ne doit
 * figurer en dur dans synapse.yaml.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_provider')]
#[ORM\HasLifecycleCallbacks]
class SynapseProvider
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifiant technique du provider (slug).
     * Doit correspondre à `getProviderName()` du client LLM.
     * Ex : 'gemini', 'ovh'
     */
    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $name = '';

    /**
     * Label affiché dans l'admin.
     * Ex : 'Google Vertex AI', 'OVH AI Endpoints'
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $label = '';

    /**
     * Credentials du provider (JSON opaque, spécifique au provider).
     */
    #[ORM\Column(type: Types::JSON)]
    private array $credentials = [];

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isEnabled = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function updateTimestamp(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
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

    public function getCredentials(): array
    {
        return $this->credentials;
    }

    public function setCredentials(array $credentials): self
    {
        $this->credentials = $credentials;
        return $this;
    }

    /**
     * Retourne une credential spécifique (lecture sécurisée avec valeur par défaut).
     */
    public function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->credentials[$key] ?? $default;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Vérifie si des credentials sont configurés.
     * La validation spécifique est faite par le client LLM correspondant.
     */
    public function isConfigured(): bool
    {
        return !empty($this->credentials);
    }
}
