<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageAttachmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SynapseMessageAttachmentRepository::class)]
#[ORM\Table(name: 'synapse_message_attachment')]
#[ORM\Index(name: 'idx_attachment_message', columns: ['message_id'])]
class SynapseMessageAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    /**
     * Stocké comme string pour éviter une relation Doctrine vers une classe abstraite.
     * La FK est gérée au niveau SQL (ON DELETE CASCADE dans la migration).
     */
    #[ORM\Column(name: 'message_id', type: 'string', length: 36)]
    private string $messageId;

    #[ORM\Column(type: 'string', length: 255)]
    private string $mimeType;

    #[ORM\Column(type: 'string', length: 500)]
    private string $filePath;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, string $messageId, string $mimeType, string $filePath)
    {
        $this->id = $id;
        $this->messageId = $messageId;
        $this->mimeType = $mimeType;
        $this->filePath = $filePath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
