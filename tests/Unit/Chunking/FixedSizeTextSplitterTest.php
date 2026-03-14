<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chunking;

use ArnaudMoncondhuy\SynapseCore\Chunking\FixedSizeTextSplitter;
use PHPUnit\Framework\TestCase;

class FixedSizeTextSplitterTest extends TestCase
{
    private FixedSizeTextSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new FixedSizeTextSplitter();
    }

    // -------------------------------------------------------------------------
    // Cas de base
    // -------------------------------------------------------------------------

    public function testReturnsOriginalTextWhenShorterThanChunkSize(): void
    {
        $chunks = $this->splitter->splitText('hello', 100, 0);

        $this->assertSame(['hello'], $chunks);
    }

    public function testSplitsTextIntoEqualChunks(): void
    {
        $text = str_repeat('a', 30);
        $chunks = $this->splitter->splitText($text, 10, 0);

        $this->assertCount(3, $chunks);
        foreach ($chunks as $chunk) {
            $this->assertSame(10, mb_strlen($chunk));
        }
    }

    public function testLastChunkShorterWhenTextNotDivisible(): void
    {
        $text = str_repeat('b', 25);
        $chunks = $this->splitter->splitText($text, 10, 0);

        $this->assertCount(3, $chunks);
        $this->assertSame(10, mb_strlen($chunks[0]));
        $this->assertSame(10, mb_strlen($chunks[1]));
        $this->assertSame(5, mb_strlen($chunks[2]));
    }

    // -------------------------------------------------------------------------
    // Overlap (chevauchement)
    // -------------------------------------------------------------------------

    public function testChunksOverlapByExpectedAmount(): void
    {
        $text = 'ABCDEFGHIJ'; // 10 chars
        // chunkSize=6, overlap=2 → chunk1=ABCDEF, chunk2=EFGHIJ
        $chunks = $this->splitter->splitText($text, 6, 2);

        $this->assertSame('ABCDEF', $chunks[0]);
        $this->assertSame('EFGHIJ', $chunks[1]);
    }

    public function testOverlapSmallerThanChunkSizeWorks(): void
    {
        // overlap doit être strictement < chunkSize pour éviter une boucle infinie
        $text = str_repeat('x', 20);
        $chunks = $this->splitter->splitText($text, 5, 4); // overlap < chunkSize

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(5, mb_strlen($chunk));
        }
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function testEmptyStringReturnsNoChunks(): void
    {
        $chunks = $this->splitter->splitText('', 100, 0);

        $this->assertSame([], $chunks);
    }

    public function testChunkSizeZeroOrNegativeReturnsWholeText(): void
    {
        $text = 'un texte quelconque';
        $this->assertSame([$text], $this->splitter->splitText($text, 0, 0));
        $this->assertSame([$text], $this->splitter->splitText($text, -1, 0));
    }

    public function testSingleCharacterChunkSize(): void
    {
        $chunks = $this->splitter->splitText('ABC', 1, 0);

        $this->assertSame(['A', 'B', 'C'], $chunks);
    }

    public function testTextExactlyChunkSizeProducesOneChunk(): void
    {
        $text = str_repeat('z', 10);
        $chunks = $this->splitter->splitText($text, 10, 0);

        $this->assertSame([$text], $chunks);
    }

    // -------------------------------------------------------------------------
    // Multibyte (UTF-8)
    // -------------------------------------------------------------------------

    public function testMultibyteCharactersCountedByCharNotByte(): void
    {
        $text = 'éàü'; // 3 chars, >3 bytes en UTF-8
        $chunks = $this->splitter->splitText($text, 1, 0);

        $this->assertCount(3, $chunks);
        $this->assertSame('é', $chunks[0]);
        $this->assertSame('à', $chunks[1]);
        $this->assertSame('ü', $chunks[2]);
    }
}
