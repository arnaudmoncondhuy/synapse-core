<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Trait\MetadataAccessorTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Trait\TimestampableEntityTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Entité SynapseConversation.
 *
 * MappedSuperclass : Permet l'extension dans les projets.
 * Les projets doivent créer leur propre entité qui étend celle-ci.
 *
 * @example
 * ```php
 * use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation as BaseConversation;
 *
 * #[ORM\Entity(repositoryClass: SynapseConversationRepository::class)]
 * #[ORM\Table(name: 'synapse_conversation')]
 * class SynapseConversation extends BaseConversation
 * {
 *     #[ORM\ManyToOne(targetEntity: User::class)]
 *     #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
 *     private User $owner;
 *
 *     public function getOwner(): ConversationOwnerInterface
 *     {
 *         return $this->owner;
 *     }
 *
 *     public function setOwner(ConversationOwnerInterface $owner): void
 *     {
 *         $this->owner = $owner;
 *     }
 * }
 * ```
 */
#[ORM\MappedSuperclass]
#[ORM\HasLifecycleCallbacks]
abstract class SynapseConversation
{
    use TimestampableEntityTrait;

    use MetadataAccessorTrait;
    /**
     * Identifiant unique (ULID au format UUID).
     */
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    protected string $id;

    /**
     * Titre de la conversation (peut être chiffré).
     *
     * Si le chiffrement est activé, ce champ contient le titre chiffré.
     * Utiliser getTitle() et setTitle() pour la gestion transparente.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $title = null;

    /**
     * Date de création (immuable).
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected \DateTimeImmutable $createdAt;

    /**
     * Date de dernière mise à jour.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    protected \DateTimeImmutable $updatedAt;

    /**
     * Statut de la conversation.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: ConversationStatus::class)]
    protected ConversationStatus $status = ConversationStatus::ACTIVE;

    /**
     * Résumé de la conversation (généré par IA).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    protected ?string $summary = null;

    /**
     * Métadonnées additionnelles (JSON).
     *
     * Permet d'ajouter des champs custom sans modifier le schéma.
     * Exemples : tags, labels, contexte spécifique, etc.
     */
    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    protected ?array $metadata = null;

    /**
     * Messages de la conversation.
     *
     * @var Collection<int, SynapseMessage>
     */
    protected Collection $messages;

    public function __construct()
    {
        $this->id = (string) new Ulid();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->status = ConversationStatus::ACTIVE;
        $this->messages = new ArrayCollection();
    }

    /**
     * Hydratation Doctrine — initialiser $messages si absent
     * (Doctrine n'appelle pas le constructeur lors du chargement depuis la BDD).
     */
    #[ORM\PostLoad]
    public function ensureMessagesInitialized(): void
    {
        // @phpstan-ignore isset.initializedProperty (Doctrine bypasses constructor on hydration, $messages may be uninitialized)
        if (!isset($this->messages)) {
            $this->messages = new ArrayCollection();
        }
    }

    // Getters et Setters

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

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

    public function getStatus(): ConversationStatus
    {
        return $this->status;
    }

    public function setStatus(ConversationStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * @return Collection<int, SynapseMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(SynapseMessage $message): self
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
        }

        return $this;
    }

    public function removeMessage(SynapseMessage $message): self
    {
        $this->messages->removeElement($message);

        return $this;
    }

    // Méthodes Helper

    /**
     * Vérifie si la conversation est active.
     */
    public function isActive(): bool
    {
        return ConversationStatus::ACTIVE === $this->status;
    }

    /**
     * Vérifie si la conversation est archivée.
     */
    public function isArchived(): bool
    {
        return ConversationStatus::ARCHIVED === $this->status;
    }

    /**
     * Vérifie si la conversation est supprimée.
     */
    public function isDeleted(): bool
    {
        return ConversationStatus::DELETED === $this->status;
    }

    /**
     * Archive la conversation.
     */
    public function archive(): self
    {
        $this->status = ConversationStatus::ARCHIVED;

        return $this;
    }

    /**
     * Soft delete la conversation.
     */
    public function softDelete(): self
    {
        $this->status = ConversationStatus::DELETED;

        return $this;
    }

    /**
     * Restaure une conversation supprimée.
     */
    public function restore(): self
    {
        if ($this->isDeleted()) {
            $this->status = ConversationStatus::ACTIVE;
        }

        return $this;
    }

    /**
     * Compte le nombre de messages.
     */
    public function getMessageCount(): int
    {
        return $this->messages->count();
    }

    // Méthodes abstraites (à implémenter dans les projets)

    /**
     * Retourne le propriétaire de la conversation.
     */
    abstract public function getOwner(): ?ConversationOwnerInterface;

    /**
     * Définit le propriétaire de la conversation.
     */
    abstract public function setOwner(ConversationOwnerInterface $owner): self;
}
