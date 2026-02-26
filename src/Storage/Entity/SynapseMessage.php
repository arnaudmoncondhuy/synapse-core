<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Entité SynapseMessage
 *
 * MappedSuperclass : Permet l'extension dans les projets.
 *
 * @example
 * ```php
 * use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage as BaseMessage;
 *
 * #[ORM\Entity(repositoryClass: SynapseMessageRepository::class)]
 * #[ORM\Table(name: 'synapse_message')]
 * #[ORM\Index(name: 'idx_conversation_created', columns: ['conversation_id', 'created_at'])]
 * class SynapseMessage extends BaseMessage
 * {
 *     #[ORM\ManyToOne(targetEntity: SynapseConversation::class, inversedBy: 'messages')]
 *     #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
 *     private SynapseConversation $conversation;
 *
 *     public function getConversation(): SynapseConversation
 *     {
 *         return $this->conversation;
 *     }
 *
 *     public function setConversation(SynapseConversation $conversation): self
 *     {
 *         $this->conversation = $conversation;
 *         return $this;
 *     }
 * }
 * ```
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class SynapseMessage
{
    /**
     * Identifiant unique (ULID au format UUID)
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    protected string $id;

    /**
     * Rôle du message (USER, MODEL, SYSTEM, FUNCTION)
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: MessageRole::class)]
    protected MessageRole $role;

    /**
     * Contenu du message (peut être chiffré)
     *
     * Si le chiffrement est activé, ce champ contient le contenu chiffré.
     * Utiliser getContent() et setContent() pour la gestion transparente.
     */
    #[ORM\Column(type: Types::TEXT)]
    protected string $content;

    /**
     * Date de création (immuable)
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected \DateTimeImmutable $createdAt;

    /**
     * Nombre de tokens dans le prompt (input)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $promptTokens = null;

    /**
     * Nombre de tokens dans la complétion (output)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $completionTokens = null;

    /**
     * Nombre de tokens dans le thinking (Gemini 2.5+)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $thinkingTokens = null;

    /**
     * Nombre total de tokens
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    protected ?int $totalTokens = null;

    /**
     * Feedback utilisateur (-1 = pouce baissé, 0 = neutre, 1 = pouce levé)
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    protected ?int $feedback = null;

    /**
     * Safety ratings (évaluations de sécurité Gemini)
     *
     * Format :
     * [
     *     'HARM_CATEGORY_HATE_SPEECH' => ['category' => '...', 'probability' => 'LOW'],
     *     'HARM_CATEGORY_DANGEROUS_CONTENT' => ['category' => '...', 'probability' => 'NEGLIGIBLE'],
     *     ...
     * ]
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $safetyRatings = null;

    /**
     * SynapseMessage bloqué par les filtres de sécurité
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    protected bool $blocked = false;

    /**
     * Métadonnées additionnelles (JSON)
     *
     * Exemples : debug_id, thinking_text, function_calls, etc.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $metadata = null;

    /**
     * Contenu déchiffré (non persistant)
     * Utilisé pour éviter que Doctrine ne sauvegarde le texte en clair 
     * lors d'un flush accidentel.
     */
    protected ?string $decryptedContent = null;

    public function __construct()
    {
        $this->id = (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters et Setters

    public function getId(): string
    {
        return $this->id;
    }

    public function getRole(): MessageRole
    {
        return $this->role;
    }

    public function setRole(MessageRole $role): self
    {
        $this->role = $role;
        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(?int $promptTokens): self
    {
        $this->promptTokens = $promptTokens;
        return $this;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(?int $completionTokens): self
    {
        $this->completionTokens = $completionTokens;
        return $this;
    }

    public function getThinkingTokens(): ?int
    {
        return $this->thinkingTokens;
    }

    public function setThinkingTokens(?int $thinkingTokens): self
    {
        $this->thinkingTokens = $thinkingTokens;
        return $this;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(?int $totalTokens): self
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
        $this->totalTokens = ($this->promptTokens ?? 0)
            + ($this->completionTokens ?? 0)
            + ($this->thinkingTokens ?? 0);
        return $this;
    }

    public function getFeedback(): ?int
    {
        return $this->feedback;
    }

    public function setFeedback(?int $feedback): self
    {
        $this->feedback = $feedback;
        return $this;
    }

    public function getSafetyRatings(): ?array
    {
        return $this->safetyRatings;
    }

    public function setSafetyRatings(?array $safetyRatings): self
    {
        $this->safetyRatings = $safetyRatings;
        return $this;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    public function setBlocked(bool $blocked): self
    {
        $this->blocked = $blocked;
        return $this;
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
     * Retourne le contenu déchiffré s'il existe, sinon le contenu brut
     */
    public function getDecryptedContent(): string
    {
        return $this->decryptedContent ?? $this->content;
    }

    /**
     * Définit le contenu déchiffré sans affecter la persistence
     */
    public function setDecryptedContent(?string $content): self
    {
        $this->decryptedContent = $content;
        return $this;
    }

    // Méthodes Helper

    /**
     * Vérifie si le message est de l'utilisateur
     */
    public function isUser(): bool
    {
        return $this->role === MessageRole::USER;
    }

    /**
     * Vérifie si le message est du modèle IA
     */
    public function isModel(): bool
    {
        return $this->role === MessageRole::MODEL;
    }

    /**
     * Vérifie si le message est système
     */
    public function isSystem(): bool
    {
        return $this->role === MessageRole::SYSTEM;
    }

    /**
     * Vérifie si le message est une fonction
     */
    public function isFunction(): bool
    {
        return $this->role === MessageRole::FUNCTION;
    }

    /**
     * Vérifie si le message doit être affiché dans l'interface
     */
    public function isDisplayable(): bool
    {
        return $this->role->isDisplayable();
    }

    /**
     * Retourne le feedback comme une évaluation
     *
     * @return string|null 'positive', 'negative', ou null
     */
    public function getFeedbackRating(): ?string
    {
        return match ($this->feedback) {
            1 => 'positive',
            -1 => 'negative',
            default => null,
        };
    }

    /**
     * Définit un feedback positif
     */
    public function likeMessage(): self
    {
        $this->feedback = 1;
        return $this;
    }

    /**
     * Définit un feedback négatif
     */
    public function dislikeMessage(): self
    {
        $this->feedback = -1;
        return $this;
    }

    /**
     * Réinitialise le feedback
     */
    public function resetFeedback(): self
    {
        $this->feedback = null;
        return $this;
    }

    // Méthodes abstraites (à implémenter dans les projets)

    /**
     * Retourne la conversation associée
     */
    abstract public function getConversation(): SynapseConversation;

    /**
     * Définit la conversation associée
     */
    abstract public function setConversation(SynapseConversation $conversation): self;
}
