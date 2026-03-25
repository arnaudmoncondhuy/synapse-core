<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Source RAG — base de connaissance déclarée par l'application hôte.
 *
 * Chaque source regroupe un ensemble de documents (SynapseRagDocument)
 * indexés par Synapse et injectés dans le contexte LLM des agents assignés.
 */
#[ORM\Entity(repositoryClass: SynapseRagSourceRepository::class)]
#[ORM\Table(name: 'synapse_rag_source')]
#[ORM\HasLifecycleCallbacks]
class SynapseRagSource
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Clé unique (slug). Référencée dans SynapseAgent::allowedRagSources.
     */
    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $slug = '';

    /**
     * Nom lisible affiché dans l'admin.
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $name = '';

    /**
     * Description de la source, injectée dans le contexte LLM.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Source active (interrogée lors des requêtes LLM).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Nombre de documents indexés (dénormalisé pour l'affichage admin).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $documentCount = 0;

    /**
     * Date de dernière indexation.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastIndexedAt = null;

    /** Statut de la dernière réindexation : ready, indexing, error. */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'ready'])]
    private string $indexingStatus = 'ready';

    /** Message d'erreur de la dernière réindexation, si applicable. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** Nombre total de fichiers à traiter (estimation pour la barre de progression). */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $totalFiles = 0;

    /** Nombre de fichiers déjà traités lors de la réindexation en cours. */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $processedFiles = 0;

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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): self
    {
        $this->slug = $slug;

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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

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

    public function getDocumentCount(): int
    {
        return $this->documentCount;
    }

    public function setDocumentCount(int $documentCount): self
    {
        $this->documentCount = $documentCount;

        return $this;
    }

    public function getLastIndexedAt(): ?\DateTimeImmutable
    {
        return $this->lastIndexedAt;
    }

    public function setLastIndexedAt(?\DateTimeImmutable $lastIndexedAt): self
    {
        $this->lastIndexedAt = $lastIndexedAt;

        return $this;
    }

    public function getIndexingStatus(): string
    {
        return $this->indexingStatus;
    }

    public function setIndexingStatus(string $indexingStatus): self
    {
        $this->indexingStatus = $indexingStatus;

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;

        return $this;
    }

    public function getTotalFiles(): int
    {
        return $this->totalFiles;
    }

    public function setTotalFiles(int $totalFiles): self
    {
        $this->totalFiles = $totalFiles;

        return $this;
    }

    public function getProcessedFiles(): int
    {
        return $this->processedFiles;
    }

    public function setProcessedFiles(int $processedFiles): self
    {
        $this->processedFiles = $processedFiles;

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
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'isActive' => $this->isActive,
            'documentCount' => $this->documentCount,
            'lastIndexedAt' => $this->lastIndexedAt?->format('c'),
        ];
    }
}
