<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use PHPUnit\Framework\TestCase;

class SynapseRagSourceTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $source = new SynapseRagSource();

        $this->assertNull($source->getId());
        $this->assertSame('', $source->getSlug());
        $this->assertSame('', $source->getName());
        $this->assertNull($source->getDescription());
        $this->assertTrue($source->isActive());
        $this->assertSame(0, $source->getDocumentCount());
        $this->assertNull($source->getLastIndexedAt());
        $this->assertSame('ready', $source->getIndexingStatus());
        $this->assertNull($source->getLastError());
        $this->assertSame(0, $source->getTotalFiles());
        $this->assertSame(0, $source->getProcessedFiles());
        $this->assertInstanceOf(\DateTimeImmutable::class, $source->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $source->getUpdatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $source = new SynapseRagSource();
        $indexedAt = new \DateTimeImmutable('2026-03-15');

        $source->setSlug('google_drive')
            ->setName('Google Drive Docs')
            ->setDescription('Company knowledge base from GDrive')
            ->setIsActive(false)
            ->setDocumentCount(150)
            ->setLastIndexedAt($indexedAt)
            ->setIndexingStatus('indexing')
            ->setLastError('Timeout on file X')
            ->setTotalFiles(200)
            ->setProcessedFiles(150);

        $this->assertSame('google_drive', $source->getSlug());
        $this->assertSame('Google Drive Docs', $source->getName());
        $this->assertSame('Company knowledge base from GDrive', $source->getDescription());
        $this->assertFalse($source->isActive());
        $this->assertSame(150, $source->getDocumentCount());
        $this->assertSame($indexedAt, $source->getLastIndexedAt());
        $this->assertSame('indexing', $source->getIndexingStatus());
        $this->assertSame('Timeout on file X', $source->getLastError());
        $this->assertSame(200, $source->getTotalFiles());
        $this->assertSame(150, $source->getProcessedFiles());
    }

    public function testToArray(): void
    {
        $source = new SynapseRagSource();
        $source->setSlug('kb')
            ->setName('Knowledge Base')
            ->setDescription('Main KB')
            ->setIsActive(true)
            ->setDocumentCount(42);

        $arr = $source->toArray();

        $this->assertSame('kb', $arr['slug']);
        $this->assertSame('Knowledge Base', $arr['name']);
        $this->assertSame('Main KB', $arr['description']);
        $this->assertTrue($arr['isActive']);
        $this->assertSame(42, $arr['documentCount']);
        $this->assertNull($arr['lastIndexedAt']);
    }
}
