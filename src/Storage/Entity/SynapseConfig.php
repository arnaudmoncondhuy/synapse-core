<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Configuration globale Synapse (singleton)
 *
 * Contient les paramètres applicatifs globaux :
 * - Rétention des données (RGPD)
 * - Langue du contexte (pour la génération de contenu)
 * - Prompt système personnalisé (appliqué à tous les LLM)
 *
 * Un seul enregistrement dans cette table (géré par SynapseConfigRepository::getGlobalConfig()).
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_config')]
class SynapseConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Nombre de jours de rétention des conversations (RGPD)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 30])]
    private int $retentionDays = 30;

    /**
     * Langue du contexte pour la génération (ex: 'fr', 'en')
     */
    #[ORM\Column(type: Types::STRING, length: 5, options: ['default' => 'fr'])]
    private string $contextLanguage = 'fr';

    /**
     * Prompt système personnalisé
     *
     * S'ajoute au prompt système de base du bundle.
     * Appliqué à tous les presets LLM.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $systemPrompt = null;

    /**
     * Mode debug global : quand activé, tous les appels LLM sont tracés et stockés en DB
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $debugMode = false;

    /**
     * Provider d'embedding par défaut pour le système.
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $embeddingProvider = null;

    /**
     * Modèle d'embedding par défaut pour le système.
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $embeddingModel = null;

    /**
     * Dimension d'embedding souhaitée (utile si le modèle supporte plusieurs dimensions).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $embeddingDimension = null;

    /**
     * Type de Vector Store à utiliser pour le stockage sémantique.
     */
    #[ORM\Column(type: Types::STRING, length: 100, options: ['default' => 'doctrine'])]
    private string $vectorStore = 'doctrine';

    /**
     * Stratégie de chunking par défaut.
     */
    #[ORM\Column(type: Types::STRING, length: 50, options: ['default' => 'recursive'])]
    private string $chunkingStrategy = 'recursive';

    /**
     * Taille maximale des chunks (en caractères).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1000])]
    private int $chunkSize = 1000;

    /**
     * Chevauchement entre les chunks (en caractères).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 200])]
    private int $chunkOverlap = 200;

    /**
     * Activer l'application des plafonds de dépense (coûts LLM).
     * Si désactivé, les limites ne bloquent plus les requêtes mais le comptage des tokens reste actif pour les stats.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $spendingLimitsEnabled = true;

    // Getters et Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRetentionDays(): int
    {
        return $this->retentionDays;
    }

    public function setRetentionDays(int $retentionDays): self
    {
        $this->retentionDays = $retentionDays;
        return $this;
    }

    public function getContextLanguage(): string
    {
        return $this->contextLanguage;
    }

    public function setContextLanguage(string $contextLanguage): self
    {
        $this->contextLanguage = $contextLanguage;
        return $this;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(?string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    public function isDebugMode(): bool
    {
        return $this->debugMode;
    }

    public function setDebugMode(bool $debugMode): self
    {
        $this->debugMode = $debugMode;
        return $this;
    }

    public function getEmbeddingProvider(): ?string
    {
        return $this->embeddingProvider;
    }

    public function setEmbeddingProvider(?string $embeddingProvider): self
    {
        $this->embeddingProvider = $embeddingProvider;
        return $this;
    }

    public function getEmbeddingModel(): ?string
    {
        return $this->embeddingModel;
    }

    public function setEmbeddingModel(?string $embeddingModel): self
    {
        $this->embeddingModel = $embeddingModel;
        return $this;
    }

    public function getEmbeddingDimension(): ?int
    {
        return $this->embeddingDimension;
    }

    public function setEmbeddingDimension(?int $embeddingDimension): self
    {
        $this->embeddingDimension = $embeddingDimension;
        return $this;
    }

    public function getVectorStore(): string
    {
        return $this->vectorStore;
    }

    public function setVectorStore(string $vectorStore): self
    {
        $this->vectorStore = $vectorStore;
        return $this;
    }

    public function getChunkingStrategy(): string
    {
        return $this->chunkingStrategy;
    }

    public function setChunkingStrategy(string $chunkingStrategy): self
    {
        $this->chunkingStrategy = $chunkingStrategy;
        return $this;
    }

    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    public function setChunkSize(int $chunkSize): self
    {
        $this->chunkSize = $chunkSize;
        return $this;
    }

    public function getChunkOverlap(): int
    {
        return $this->chunkOverlap;
    }

    public function setChunkOverlap(int $chunkOverlap): self
    {
        $this->chunkOverlap = $chunkOverlap;
        return $this;
    }

    public function isSpendingLimitsEnabled(): bool
    {
        return $this->spendingLimitsEnabled;
    }

    public function setSpendingLimitsEnabled(bool $spendingLimitsEnabled): self
    {
        $this->spendingLimitsEnabled = $spendingLimitsEnabled;
        return $this;
    }

    /**
     * Convertit la config globale en tableau
     *
     * @return array Configuration formatée pour DatabaseConfigProvider
     */
    public function toArray(): array
    {
        return [
            'retention' => [
                'days' => $this->retentionDays,
            ],
            'context' => [
                'language' => $this->contextLanguage,
            ],
            'system_prompt' => $this->systemPrompt,
            'debug_mode' => $this->debugMode,
            'spending_limits_enabled' => $this->spendingLimitsEnabled,
        ];
    }
}
