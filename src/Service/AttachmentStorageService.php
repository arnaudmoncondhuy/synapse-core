<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Service;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
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
        $this->storageDir = $projectDir . '/var/synapse/attachments';
    }

    /**
     * Store a base64-encoded image as a file and return the attachment entity.
     *
     * @param array{mime_type: string, data: string} $image
     */
    public function store(array $image, SynapseMessage $message): SynapseMessageAttachment
    {
        $uuid = $this->generateUuid();
        $ext = $this->mimeToExt($image['mime_type']);
        $conversationId = $message->getConversation()->getId();
        $dir = $this->storageDir . '/' . $conversationId;
        $relativePath = $conversationId . '/' . $uuid . '.' . $ext;
        $absolutePath = $this->storageDir . '/' . $relativePath;

        $fs = new Filesystem();
        $fs->mkdir($dir);
        $fs->dumpFile($absolutePath, base64_decode($image['data']));

        $attachment = new SynapseMessageAttachment($uuid, $message, $image['mime_type'], $relativePath);
        $this->em->persist($attachment);

        return $attachment;
    }

    public function delete(SynapseMessageAttachment $attachment): void
    {
        $absolutePath = $this->storageDir . '/' . $attachment->getFilePath();
        $fs = new Filesystem();
        if ($fs->exists($absolutePath)) {
            $fs->remove($absolutePath);
        }
        // Try to remove empty parent dir
        $dir = dirname($absolutePath);
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            $fs->remove($dir);
        }
    }

    public function getAbsolutePath(SynapseMessageAttachment $attachment): string
    {
        return $this->storageDir . '/' . $attachment->getFilePath();
    }

    private function generateUuid(): string
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
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
