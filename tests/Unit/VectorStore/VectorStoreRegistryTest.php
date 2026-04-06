<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\VectorStore;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;
use ArnaudMoncondhuy\SynapseCore\VectorStore\VectorStoreRegistry;
use PHPUnit\Framework\TestCase;

class VectorStoreRegistryTest extends TestCase
{
    public function testGetVectorStoreReturnsRegistered(): void
    {
        $store = $this->createStub(VectorStoreInterface::class);
        $registry = new VectorStoreRegistry(new \ArrayIterator(['memory' => $store]));

        $this->assertSame($store, $registry->getVectorStore('memory'));
    }

    public function testGetVectorStoreFallsBackToDoctrine(): void
    {
        $doctrine = $this->createStub(VectorStoreInterface::class);
        $registry = new VectorStoreRegistry(new \ArrayIterator(['doctrine' => $doctrine]));

        $this->assertSame($doctrine, $registry->getVectorStore('unknown'));
    }

    public function testGetVectorStoreFallsBackToFirstWhenNoDoctrine(): void
    {
        $first = $this->createStub(VectorStoreInterface::class);
        $registry = new VectorStoreRegistry(new \ArrayIterator(['custom' => $first]));

        $this->assertSame($first, $registry->getVectorStore('unknown'));
    }

    public function testGetVectorStoreThrowsWhenEmpty(): void
    {
        $registry = new VectorStoreRegistry(new \ArrayIterator([]));

        $this->expectException(\LogicException::class);
        $registry->getVectorStore('any');
    }

    public function testGetAvailableAliases(): void
    {
        $store = $this->createStub(VectorStoreInterface::class);
        $registry = new VectorStoreRegistry(new \ArrayIterator(['memory' => $store, 'doctrine' => $store]));

        $this->assertSame(['memory', 'doctrine'], $registry->getAvailableAliases());
    }
}
