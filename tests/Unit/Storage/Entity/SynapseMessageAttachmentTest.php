<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment;
use PHPUnit\Framework\TestCase;

class SynapseMessageAttachmentTest extends TestCase
{
    public function testConstructorSetsFields(): void
    {
        $att = new SynapseMessageAttachment('att-1', 'msg-1', 'application/pdf', '/uploads/doc.pdf', 'rapport.pdf');

        $this->assertSame('att-1', $att->getId());
        $this->assertSame('msg-1', $att->getMessageId());
        $this->assertSame('application/pdf', $att->getMimeType());
        $this->assertSame('/uploads/doc.pdf', $att->getFilePath());
        $this->assertSame('rapport.pdf', $att->getOriginalName());
        $this->assertInstanceOf(\DateTimeImmutable::class, $att->getCreatedAt());
    }

    public function testConstructorWithNullOriginalName(): void
    {
        $att = new SynapseMessageAttachment('att-2', 'msg-2', 'image/png', '/uploads/image.png');

        $this->assertNull($att->getOriginalName());
    }

    public function testGetDisplayNameReturnsOriginalName(): void
    {
        $att = new SynapseMessageAttachment('a', 'm', 'application/pdf', '/path/doc.pdf', 'Mon Rapport.pdf');
        $this->assertSame('Mon Rapport.pdf', $att->getDisplayName());
    }

    public function testGetDisplayNameFallsBackToExtension(): void
    {
        $att = new SynapseMessageAttachment('a', 'm', 'application/pdf', '/path/doc.pdf');
        $this->assertSame('PDF', $att->getDisplayName());
    }

    public function testGetDisplayNameFallsBackToMimeSubtype(): void
    {
        $att = new SynapseMessageAttachment('a', 'm', 'image/png', '/path/noext');
        $this->assertSame('PNG', $att->getDisplayName());
    }

    public function testGetDisplayNameWithEmptyOriginalName(): void
    {
        $att = new SynapseMessageAttachment('a', 'm', 'image/jpeg', '/path/photo.jpg', '');
        $this->assertSame('JPG', $att->getDisplayName());
    }
}
