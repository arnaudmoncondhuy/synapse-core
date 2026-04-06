<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseCore\Service\AttachmentStorageService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AttachmentStorageServiceTest extends TestCase
{
    public function testStoreRejectsUnsupportedMimeType(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new AttachmentStorageService($em, '/tmp');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/MIME type/');

        $service->store(
            ['mime_type' => 'application/x-malicious', 'data' => 'abc'],
            'msg-1',
            'conv-1',
        );
    }

    public function testStoreRejectsInvalidBase64(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new AttachmentStorageService($em, '/tmp');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/base64/');

        $service->store(
            ['mime_type' => 'text/plain', 'data' => '!!!invalid!!!'],
            'msg-1',
            'conv-1',
        );
    }

    public function testStoreWithValidTextAttachment(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');

        $service = new AttachmentStorageService($em, '/tmp');

        $data = base64_encode('Hello world text content');
        $entity = $service->store(
            ['mime_type' => 'text/plain', 'data' => $data, 'name' => 'readme.txt'],
            'msg-1',
            'conv-1',
        );

        $this->assertSame('text/plain', $entity->getMimeType());
        $this->assertSame('readme.txt', $entity->getOriginalName());
        $this->assertStringContainsString('conv-1/', $entity->getFilePath());

        // Cleanup generated file
        $path = '/tmp/var/synapse/attachments/'.$entity->getFilePath();
        if (file_exists($path)) {
            unlink($path);
            $dir = dirname($path);
            if (is_dir($dir)) {
                @rmdir($dir);
            }
        }
    }

    public function testGetAbsolutePath(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $service = new AttachmentStorageService($em, '/app');

        $attachment = $this->createStub(\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment::class);
        $attachment->method('getFilePath')->willReturn('conv-1/file.txt');

        $this->assertSame('/app/var/synapse/attachments/conv-1/file.txt', $service->getAbsolutePath($attachment));
    }
}
