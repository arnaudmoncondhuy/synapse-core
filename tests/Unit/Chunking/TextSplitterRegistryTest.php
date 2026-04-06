<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chunking;

use ArnaudMoncondhuy\SynapseCore\Chunking\TextSplitterRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\TextSplitterInterface;
use PHPUnit\Framework\TestCase;

class TextSplitterRegistryTest extends TestCase
{
    public function testGetSplitterReturnsRegisteredSplitter(): void
    {
        $splitter = $this->createStub(TextSplitterInterface::class);
        $registry = new TextSplitterRegistry(new \ArrayIterator(['fixed' => $splitter]));

        $this->assertSame($splitter, $registry->getSplitter('fixed'));
    }

    public function testGetSplitterFallsBackToRecursive(): void
    {
        $recursive = $this->createStub(TextSplitterInterface::class);
        $registry = new TextSplitterRegistry(new \ArrayIterator(['recursive' => $recursive]));

        $this->assertSame($recursive, $registry->getSplitter('unknown'));
    }

    public function testGetSplitterFallsBackToFirstWhenNoRecursive(): void
    {
        $first = $this->createStub(TextSplitterInterface::class);
        $registry = new TextSplitterRegistry(new \ArrayIterator(['custom' => $first]));

        $this->assertSame($first, $registry->getSplitter('unknown'));
    }

    public function testGetSplitterThrowsWhenEmpty(): void
    {
        $registry = new TextSplitterRegistry(new \ArrayIterator([]));

        $this->expectException(\LogicException::class);
        $registry->getSplitter('any');
    }

    public function testAddSplitter(): void
    {
        $registry = new TextSplitterRegistry(new \ArrayIterator([]));
        $splitter = $this->createStub(TextSplitterInterface::class);

        $registry->addSplitter('new', $splitter);

        $this->assertSame($splitter, $registry->getSplitter('new'));
    }
}
