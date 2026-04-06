<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Service;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

class AttachmentStorageService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
    ];

    private string $storageDir;

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->storageDir = $projectDir.'/var/synapse/attachments';
    }

    /**
     * Store a base64-encoded attachment as a file and return the attachment entity.
     *
     * @param array{mime_type: string, data: string, name?: string} $attachment
     *
     * @throws \InvalidArgumentException if MIME type is not allowed or content doesn't match
     */
    public function store(array $attachment, string $messageId, string $conversationId): SynapseMessageAttachment
    {
        $declaredMime = $attachment['mime_type'];
        if (!isset(self::ALLOWED_MIME_TYPES[$declaredMime])) {
            throw new \InvalidArgumentException(sprintf('MIME type "%s" is not allowed.', $declaredMime));
        }

        $decoded = base64_decode($attachment['data'], true);
        if (false === $decoded || '' === $decoded) {
            throw new \InvalidArgumentException('Invalid base64 data.');
        }

        // Validate actual content matches declared MIME type
        $finfo = new \finfo(\FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($decoded);
        if (false !== $detectedMime && $detectedMime !== $declaredMime) {
            // Allow application/octet-stream as finfo fallback for heic/heif
            if ('application/octet-stream' !== $detectedMime) {
                throw new \InvalidArgumentException(sprintf('Content MIME type "%s" does not match declared "%s".', $detectedMime, $declaredMime));
            }
        }

        $uuid = $this->generateUuid();
        $ext = self::ALLOWED_MIME_TYPES[$declaredMime];
        $dir = $this->storageDir.'/'.$conversationId;
        $relativePath = $conversationId.'/'.$uuid.'.'.$ext;
        $absolutePath = $this->storageDir.'/'.$relativePath;

        $fs = new Filesystem();
        $fs->mkdir($dir);
        $fs->dumpFile($absolutePath, $decoded);

        $originalName = isset($attachment['name']) && is_string($attachment['name']) && '' !== $attachment['name'] ? $attachment['name'] : null;
        $entity = new SynapseMessageAttachment($uuid, $messageId, $attachment['mime_type'], $relativePath, $originalName);
        $this->em->persist($entity);

        return $entity;
    }

    public function delete(SynapseMessageAttachment $attachment): void
    {
        $absolutePath = $this->storageDir.'/'.$attachment->getFilePath();
        $fs = new Filesystem();
        if ($fs->exists($absolutePath)) {
            $fs->remove($absolutePath);
        }
        // Supprimer le dossier parent s'il est vide
        $dir = dirname($absolutePath);
        if (is_dir($dir) && 2 === count((array) scandir($dir))) {
            $fs->remove($dir);
        }
    }

    public function getAbsolutePath(SynapseMessageAttachment $attachment): string
    {
        return $this->storageDir.'/'.$attachment->getFilePath();
    }

    /**
     * Supprime tous les attachments d'un message (fichiers + entités).
     */
    public function deleteByMessageId(string $messageId): void
    {
        $attachments = $this->em->getRepository(SynapseMessageAttachment::class)->findBy(['messageId' => $messageId]);
        foreach ($attachments as $attachment) {
            $this->delete($attachment);
            $this->em->remove($attachment);
        }
    }

    private function generateUuid(): string
    {
        return Uuid::v4()->toRfc4122();
    }
}
