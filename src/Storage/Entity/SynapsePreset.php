<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Preset de configuration LLM (multi-preset sans scope)
 *
 * Un preset associe un provider + un modèle + des paramètres de génération.
 * Un seul preset peut être actif à la fois (enforced by application).
 *
 * Les credentials du provider sont stockés dans SynapseProvider.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_preset')]
#[ORM\HasLifecycleCallbacks]
class SynapsePreset
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nom lisible du preset
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name = 'Preset par défaut';

    /**
     * Preset actif (un seul actif à la fois)
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isActive = false;

    /**
     * Provider LLM actif pour ce preset.
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $providerName = '';

    /**
     * Modèle LLM à utiliser (dépend du provider actif).
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $model = '';

    /**
     * Options spécifiques au provider (ex: safetySettings, thinkingBudget, reasoningEffort)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $providerOptions = null;

    // Generation Config

    /**
     * Température (0.0 - 2.0) - Créativité
     */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 1.0])]
    private float $generationTemperature = 1.0;

    /**
     * Top P (0.0 - 1.0) - Nucleus sampling
     */
    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.95])]
    private float $generationTopP = 0.95;

    /**
     * Top K (1 - 100) - Filtrage tokens (nullable si non supporté par le modèle)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['default' => 40])]
    private ?int $generationTopK = 40;

    /**
     * Nombre maximum de tokens de sortie
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $generationMaxOutputTokens = null;

    /**
     * Séquences d'arrêt (JSON array)
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $generationStopSequences = null;

    /**
     * Activer le streaming (SSE). Si désactivé, mode synchrone pour debug facile
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $streamingEnabled = true;

    /**
     * Date de dernière mise à jour
     */
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

    // Getters et Setters

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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;
        return $this;
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

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getProviderOptions(): ?array
    {
        return $this->providerOptions;
    }

    public function setProviderOptions(?array $providerOptions): self
    {
        $this->providerOptions = $providerOptions;
        return $this;
    }

    public function getGenerationTemperature(): float
    {
        return $this->generationTemperature;
    }

    public function setGenerationTemperature(float $generationTemperature): self
    {
        $this->generationTemperature = $generationTemperature;
        return $this;
    }

    public function getGenerationTopP(): float
    {
        return $this->generationTopP;
    }

    public function setGenerationTopP(float $generationTopP): self
    {
        $this->generationTopP = $generationTopP;
        return $this;
    }

    public function getGenerationTopK(): ?int
    {
        return $this->generationTopK;
    }

    public function setGenerationTopK(?int $generationTopK): self
    {
        $this->generationTopK = $generationTopK;
        return $this;
    }

    public function getGenerationMaxOutputTokens(): ?int
    {
        return $this->generationMaxOutputTokens;
    }

    public function setGenerationMaxOutputTokens(?int $generationMaxOutputTokens): self
    {
        $this->generationMaxOutputTokens = $generationMaxOutputTokens;
        return $this;
    }

    public function getGenerationStopSequences(): ?array
    {
        return $this->generationStopSequences;
    }

    public function setGenerationStopSequences(?array $generationStopSequences): self
    {
        $this->generationStopSequences = $generationStopSequences;
        return $this;
    }

    public function isStreamingEnabled(): bool
    {
        return $this->streamingEnabled;
    }

    public function setStreamingEnabled(bool $streamingEnabled): self
    {
        $this->streamingEnabled = $streamingEnabled;
        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Convertit le preset en tableau pour ChatService / LLM clients
     *
     * @return array Configuration formatée pour les services LLM
     */
    public function toArray(): array
    {
        $config = [
            'provider'  => $this->providerName,
            'model'     => $this->model,
        ];

        // Generation Config
        $config['generation_config'] = [
            'temperature' => $this->generationTemperature,
            'top_p'       => $this->generationTopP,
            'top_k'       => $this->generationTopK,
        ];

        if ($this->generationMaxOutputTokens !== null) {
            $config['generation_config']['max_output_tokens'] = $this->generationMaxOutputTokens;
        }

        if ($this->generationStopSequences !== null && count($this->generationStopSequences) > 0) {
            $config['generation_config']['stop_sequences'] = $this->generationStopSequences;
        }

        // Streaming Mode
        $config['streaming_enabled'] = $this->streamingEnabled;

        // Provider Options (Fusion directe)
        if ($this->providerOptions !== null) {
            $config = array_merge($config, $this->providerOptions);
        }

        return $config;
    }
}
