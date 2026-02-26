<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Tracking centralisé de l'usage des tokens IA
 *
 * Permet de tracker la consommation de tokens pour toutes les fonctionnalités IA
 * de l'application (pas seulement les conversations).
 *
 * Exemples : génération de titres, résumés, emails automatiques, calendrier IA, etc.
 *
 * Note : Les conversations (chat) sont trackées via SynapseMessage.tokens,
 *        cette table est pour les tâches automatisées et agrégations.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_token_usage')]
#[ORM\Index(name: 'idx_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_module_model', columns: ['module', 'model'])]
#[ORM\HasLifecycleCallbacks]
class SynapseTokenUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Module ou fonctionnalité concernée
     *
     * Exemples : 'chat', 'title_generation', 'gmail', 'calendar', 'summarization'
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $module;

    /**
     * Action spécifique dans le module
     *
     * Exemples : 'chat_turn', 'generate_title', 'email_draft', 'event_suggestion'
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $action;

    /**
     * Modèle IA utilisé
     *
     * Exemples : 'gemini-2.5-flash', 'gemini-2.0-flash-exp', 'gemini-1.5-pro'
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $model;

    /**
     * Nombre de tokens dans le prompt (input)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $promptTokens = 0;

    /**
     * Nombre de tokens dans la complétion (output)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $completionTokens = 0;

    /**
     * Nombre de tokens dans le thinking (Gemini 2.5+)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $thinkingTokens = 0;

    /**
     * Nombre total de tokens
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $totalTokens = 0;

    /**
     * ID de l'utilisateur concerné (nullable pour tâches système)
     *
     * Note : Type mixte (int|string) pour supporter différents types d'ID
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $userId = null;

    /**
     * ID de la conversation concernée (si applicable)
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $conversationId = null;

    /**
     * Date de création
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Métadonnées additionnelles (JSON)
     *
     * Exemples : coût estimé, durée d'exécution, erreurs, etc.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters et Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function setModule(string $module): self
    {
        $this->module = $module;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
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

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(int $promptTokens): self
    {
        $this->promptTokens = $promptTokens;
        return $this;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(int $completionTokens): self
    {
        $this->completionTokens = $completionTokens;
        return $this;
    }

    public function getThinkingTokens(): int
    {
        return $this->thinkingTokens;
    }

    public function setThinkingTokens(int $thinkingTokens): self
    {
        $this->thinkingTokens = $thinkingTokens;
        return $this;
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(int $totalTokens): self
    {
        $this->totalTokens = $totalTokens;
        return $this;
    }

    /**
     * Calcule et définit automatiquement le total de tokens
     */
    public function calculateTotalTokens(): self
    {
        // Total = Prompt + Completion + Thinking
        $this->totalTokens = $this->promptTokens + $this->completionTokens + $this->thinkingTokens;
        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    /**
     * Récupère une métadonnée spécifique
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    /**
     * Définit une métadonnée spécifique
     */
    public function setMetadataValue(string $key, mixed $value): self
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Calcule le coût estimé en dollars
     *
     * @param array $pricing Tarifs par modèle ['input' => float, 'output' => float] ($/1M tokens)
     * @return float Coût en dollars
     */
    public function calculateCost(array $pricing): float
    {
        $inputCost = ($this->promptTokens / 1_000_000) * ($pricing['input'] ?? 0);
        // Chez Vertex, Thinking + Completion = Total Output
        $outputCost = (($this->completionTokens + $this->thinkingTokens) / 1_000_000) * ($pricing['output'] ?? 0);

        return $inputCost + $outputCost;
    }
}
