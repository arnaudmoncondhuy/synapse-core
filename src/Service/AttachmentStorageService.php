<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Service;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

class AttachmentStorageService
{
    private string $storageDir;

    public function __construct(
        private EntityManagerInterface $em,
        string $projectDir,
    ) {
        $this->storageDir = $projectDir.'/var/synapse/attachments';
    }

    /**
     * Store a base64-encoded image as a file and return the attachment entity.
     *
     * @param array{mime_type: string, data: string} $image
     */
    public function store(array $image, string $messageId, string $conversationId): SynapseMessageAttachment
    {
        $uuid = $this->generateUuid();
        $ext = $this->mimeToExt($image['mime_type']);
        $dir = $this->storageDir.'/'.$conversationId;
        $relativePath = $conversationId.'/'.$uuid.'.'.$ext;
        $absolutePath = $this->storageDir.'/'.$relativePath;

        $fs = new Filesystem();
        $fs->mkdir($dir);
        $fs->dumpFile($absolutePath, base64_decode($image['data']));

        $attachment = new SynapseMessageAttachment($uuid, $messageId, $image['mime_type'], $relativePath);
        $this->em->persist($attachment);

        return $attachment;
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
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF),
            mt_rand(0, 0xFFFF),
            mt_rand(0, 0x0FFF) | 0x4000,
            mt_rand(0, 0x3FFF) | 0x8000,
            mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFFF)
        );
    }

    private function mimeToExt(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'bin',
        };
    }
}
