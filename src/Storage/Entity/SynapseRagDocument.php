<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagDocumentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Document RAG — chunk vectorisé appartenant à une source RAG.
 *
 * Stocke le texte découpé, son embedding et les métadonnées du document d'origine.
 * Sur PostgreSQL, la colonne `embedding` devrait être migrée en type `vector`
 * pour bénéficier des opérateurs pgvector natifs.
 */
#[ORM\Entity(repositoryClass: SynapseRagDocumentRepository::class)]
#[ORM\Table(name: 'synapse_rag_document')]
#[ORM\Index(columns: ['source_id', 'source_identifier'], name: 'idx_rag_doc_source_identifier')]
class SynapseRagDocument
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Source RAG parente.
     */
    #[ORM\ManyToOne(targetEntity: SynapseRagSource::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?SynapseRagSource $source = null;

    /**
     * Texte du chunk.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $content = '';

    /**
     * Vecteur d'embedding.
     *
     * @var array<int, float>
     */
    #[ORM\Column(type: 'json')]
    private array $embedding = [];

    /**
     * Métadonnées libres fournies par l'hôte (filename, drive_id, url, folder...).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    /**
     * Index du chunk dans le document d'origine (0-based).
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $chunkIndex = 0;

    /**
     * Nombre total de chunks pour ce document.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $totalChunks = 1;

    /**
     * Clé de déduplication fournie par l'hôte (ex: drive_file_id).
     * Permet de supprimer les anciens chunks lors d'un reindex.
     */
    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $sourceIdentifier = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): ?SynapseRagSource
    {
        return $this->source;
    }

    public function setSource(?SynapseRagSource $source): self
    {
        $this->source = $source;

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

    /**
     * @return array<int, float>
     */
    public function getEmbedding(): array
    {
        return $this->embedding;
    }

    /**
     * @param array<int, float> $embedding
     */
    public function setEmbedding(array $embedding): self
    {
        $this->embedding = $embedding;

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

    public function getChunkIndex(): int
    {
        return $this->chunkIndex;
    }

    public function setChunkIndex(int $chunkIndex): self
    {
        $this->chunkIndex = $chunkIndex;

        return $this;
    }

    public function getTotalChunks(): int
    {
        return $this->totalChunks;
    }

    public function setTotalChunks(int $totalChunks): self
    {
        $this->totalChunks = $totalChunks;

        return $this;
    }

    public function getSourceIdentifier(): string
    {
        return $this->sourceIdentifier;
    }

    public function setSourceIdentifier(string $sourceIdentifier): self
    {
        $this->sourceIdentifier = $sourceIdentifier;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
