<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagDocument;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use PHPUnit\Framework\TestCase;

class SynapseRagDocumentTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $doc = new SynapseRagDocument();

        $this->assertNull($doc->getId());
        $this->assertNull($doc->getSource());
        $this->assertSame('', $doc->getContent());
        $this->assertSame([], $doc->getEmbedding());
        $this->assertNull($doc->getMetadata());
        $this->assertSame(0, $doc->getChunkIndex());
        $this->assertSame(1, $doc->getTotalChunks());
        $this->assertSame('', $doc->getSourceIdentifier());
        $this->assertInstanceOf(\DateTimeImmutable::class, $doc->getCreatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $doc = new SynapseRagDocument();
        $source = new SynapseRagSource();
        $embedding = [0.1, 0.2, 0.3, 0.4];

        $doc->setSource($source)
            ->setContent('This is a chunk of text.')
            ->setEmbedding($embedding)
            ->setMetadata(['filename' => 'doc.pdf', 'page' => 3])
            ->setChunkIndex(2)
            ->setTotalChunks(10)
            ->setSourceIdentifier('drive_file_abc');

        $this->assertSame($source, $doc->getSource());
        $this->assertSame('This is a chunk of text.', $doc->getContent());
        $this->assertSame($embedding, $doc->getEmbedding());
        $this->assertSame('doc.pdf', $doc->getMetadata()['filename']);
        $this->assertSame(2, $doc->getChunkIndex());
        $this->assertSame(10, $doc->getTotalChunks());
        $this->assertSame('drive_file_abc', $doc->getSourceIdentifier());
    }

    public function testSourceCanBeSetToNull(): void
    {
        $doc = new SynapseRagDocument();
        $doc->setSource(new SynapseRagSource());
        $doc->setSource(null);

        $this->assertNull($doc->getSource());
    }
}
