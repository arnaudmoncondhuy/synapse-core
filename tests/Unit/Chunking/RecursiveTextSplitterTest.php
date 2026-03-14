<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chunking;

use ArnaudMoncondhuy\SynapseCore\Chunking\RecursiveTextSplitter;
use PHPUnit\Framework\TestCase;

class RecursiveTextSplitterTest extends TestCase
{
    private RecursiveTextSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new RecursiveTextSplitter();
    }

    // -------------------------------------------------------------------------
    // Cas de base
    // -------------------------------------------------------------------------

    public function testReturnsOriginalTextWhenShorterThanChunkSize(): void
    {
        $chunks = $this->splitter->splitText('court texte', 1000, 0);

        $this->assertSame(['court texte'], $chunks);
    }

    public function testEmptyStringReturnsNoChunks(): void
    {
        $chunks = $this->splitter->splitText('', 100, 0);

        $this->assertEmpty($chunks);
    }

    // -------------------------------------------------------------------------
    // Séparateur paragraphe (\n\n)
    // -------------------------------------------------------------------------

    public function testSplitsOnDoubleNewlineFirst(): void
    {
        $text = "Paragraphe un.\n\nParagraphe deux.\n\nParagraphe trois.";
        // chunkSize=20 → chaque para (~14-17 chars) tient seul mais pas combiné
        $chunks = $this->splitter->splitText($text, 20, 0);

        $this->assertCount(3, $chunks);
        $this->assertSame('Paragraphe un.', $chunks[0]);
        $this->assertSame('Paragraphe deux.', $chunks[1]);
        $this->assertSame('Paragraphe trois.', $chunks[2]);
    }

    public function testMergesParagraphsWhenTheyFitInChunkSize(): void
    {
        $text = "A\n\nB\n\nC";
        // chunkSize assez grand pour tout tenir en un seul chunk
        $chunks = $this->splitter->splitText($text, 1000, 0);

        $this->assertCount(1, $chunks);
    }

    // -------------------------------------------------------------------------
    // Séparateur ligne (\n)
    // -------------------------------------------------------------------------

    public function testSplitsOnSingleNewlineWhenNoDoubleNewline(): void
    {
        $a = str_repeat('a', 20);
        $b = str_repeat('b', 20);
        $text = "$a\n$b";
        $chunks = $this->splitter->splitText($text, 25, 0);

        $this->assertCount(2, $chunks);
        $this->assertSame($a, $chunks[0]);
        $this->assertSame($b, $chunks[1]);
    }

    // -------------------------------------------------------------------------
    // Séparateur espace
    // -------------------------------------------------------------------------

    public function testSplitsOnSpaceWhenNoNewline(): void
    {
        $text = str_repeat('mot ', 50); // ~200 chars
        $chunks = $this->splitter->splitText($text, 20, 0);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(20, mb_strlen($chunk));
        }
    }

    // -------------------------------------------------------------------------
    // Séparateur char-by-char
    // -------------------------------------------------------------------------

    public function testSplitsCharByCharWhenNoSeparatorFound(): void
    {
        // Texte sans espaces ni sauts de ligne
        $text = str_repeat('x', 30);
        $chunks = $this->splitter->splitText($text, 10, 0);

        $this->assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(10, mb_strlen($chunk));
        }
    }

    // -------------------------------------------------------------------------
    // Taille de chunks et contenu
    // -------------------------------------------------------------------------

    public function testNoneOfTheChunksExceedsChunkSize(): void
    {
        $text = implode("\n\n", array_fill(0, 20, str_repeat('w', 150)));
        $chunkSize = 200;
        $chunks = $this->splitter->splitText($text, $chunkSize, 0);

        foreach ($chunks as $i => $chunk) {
            $this->assertLessThanOrEqual(
                $chunkSize,
                mb_strlen($chunk),
                "Chunk $i dépasse chunkSize: ".mb_strlen($chunk).' > '.$chunkSize,
            );
        }
    }

    public function testAllOriginalContentIsPreserved(): void
    {
        $text = "Premier paragraphe avec du texte.\n\nDeuxième paragraphe aussi.\n\nTroisième.";
        $chunks = $this->splitter->splitText($text, 30, 0);

        $reconstructed = implode(' ', $chunks);

        // Chaque mot doit apparaître dans la reconstruction
        foreach (explode(' ', preg_replace('/\s+/', ' ', $text)) as $word) {
            $this->assertStringContainsString(trim($word), $reconstructed);
        }
    }

    // -------------------------------------------------------------------------
    // Cas limites
    // -------------------------------------------------------------------------

    public function testHandlesTextWithOnlyNewlines(): void
    {
        $chunks = $this->splitter->splitText("\n\n\n\n", 100, 0);

        // Rien de substantiel → aucun chunk ou un chunk vide non retourné
        $this->assertEmpty($chunks);
    }

    public function testHandlesSingleLongWordWithoutSeparator(): void
    {
        $word = str_repeat('z', 50);
        $chunks = $this->splitter->splitText($word, 10, 0);

        $this->assertNotEmpty($chunks);
        // En mode char-by-char, chaque chunk <= 10
        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(10, mb_strlen($chunk));
        }
    }
}
