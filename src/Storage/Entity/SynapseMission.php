<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMissionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Mission d'agent — configuration prédéfinie combinant un prompt système,
 * un preset LLM optionnel, et un ton de réponse optionnel.
 *
 * Les missions builtin (isBuiltin = true) sont fournies par le bundle et ne
 * peuvent pas être supprimées depuis l'admin.
 *
 * Utilisation : ChatService::ask($msg, ['mission' => 'support_client'])
 */
#[ORM\Entity(repositoryClass: SynapseMissionRepository::class)]
#[ORM\Table(name: 'synapse_mission')]
#[ORM\HasLifecycleCallbacks]
class SynapseMission
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Clé unique (slug). Utilisée dans ChatService::ask(['mission' => 'support_client']).
     */
    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $key = '';

    /**
     * Emoji d'illustration affiché dans l'interface.
     */
    #[ORM\Column(type: Types::STRING, length: 10)]
    private string $emoji = '';

    /**
     * Nom lisible affiché dans l'interface.
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name = '';

    /**
     * Description courte de la mission.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /**
     * Instructions injectées dans le system prompt pour définir l'objectif de la mission.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $systemPrompt = '';

    /**
     * Preset LLM optionnel pour cette mission.
     * Si null, utilise le preset actif global.
     */
    #[ORM\ManyToOne(targetEntity: SynapsePreset::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SynapsePreset $preset = null;

    /**
     * Ton de réponse optionnel pour cette mission.
     * Si non-null, ses instructions sont fusionnées au system prompt.
     */
    #[ORM\ManyToOne(targetEntity: SynapseTone::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SynapseTone $tone = null;

    /**
     * Mission fournie par le bundle (ne peut pas être supprimée depuis l'admin).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isBuiltin = true;

    /**
     * Mission activée (visible dans les sélecteurs de l'interface).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Ordre d'affichage dans les listes (plus petit = affiché en premier).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

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

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function setEmoji(string $emoji): self
    {
        $this->emoji = $emoji;
        return $this;
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

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;
        return $this;
    }

    public function getPreset(): ?SynapsePreset
    {
        return $this->preset;
    }

    public function setPreset(?SynapsePreset $preset): self
    {
        $this->preset = $preset;
        return $this;
    }

    public function getTone(): ?SynapseTone
    {
        return $this->tone;
    }

    public function setTone(?SynapseTone $tone): self
    {
        $this->tone = $tone;
        return $this;
    }

    public function isBuiltin(): bool
    {
        return $this->isBuiltin;
    }

    public function setIsBuiltin(bool $isBuiltin): self
    {
        $this->isBuiltin = $isBuiltin;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;
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

    /**
     * Représentation tableau pour Twig.
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'emoji'       => $this->emoji,
            'name'        => $this->name,
            'description' => $this->description,
            'systemPrompt' => $this->systemPrompt,
            'preset'      => $this->preset?->getName(),
            'tone'        => $this->tone?->getKey(),
            'isBuiltin'   => $this->isBuiltin,
            'isActive'    => $this->isActive,
        ];
    }
}
