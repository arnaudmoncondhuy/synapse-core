<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\VectorStore;

use ArnaudMoncondhuy\SynapseCore\VectorStore\NullVectorStore;
use PHPUnit\Framework\TestCase;

class NullVectorStoreTest extends TestCase
{
    public function testSaveMemoryDoesNothing(): void
    {
        $store = new NullVectorStore();
        $store->saveMemory([0.1, 0.2], ['text' => 'test']);

        $this->assertTrue(true);
    }

    public function testSearchSimilarReturnsEmptyArray(): void
    {
        $store = new NullVectorStore();

        $this->assertSame([], $store->searchSimilar([0.1], 5));
    }
}
