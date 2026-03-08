<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\ORM\Mapping as ORM;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageAttachmentRepository;

#[ORM\Entity(repositoryClass: SynapseMessageAttachmentRepository::class)]
#[ORM\Table(name: 'synapse_message_attachment')]
class SynapseMessageAttachment
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: SynapseMessage::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(name: 'message_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SynapseMessage $message;

    #[ORM\Column(type: 'string', length: 255)]
    private string $mimeType;

    #[ORM\Column(type: 'string', length: 500)]
    private string $filePath;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $id, SynapseMessage $message, string $mimeType, string $filePath)
    {
        $this->id = $id;
        $this->message = $message;
        $this->mimeType = $mimeType;
        $this->filePath = $filePath;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getMessage(): SynapseMessage { return $this->message; }
    public function getMimeType(): string { return $this->mimeType; }
    public function getFilePath(): string { return $this->filePath; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
