<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\VectorStore;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\VectorStore\DynamicVectorStore;
use ArnaudMoncondhuy\SynapseCore\VectorStore\VectorStoreRegistry;
use PHPUnit\Framework\TestCase;

class DynamicVectorStoreTest extends TestCase
{
    public function testSaveMemoryDelegatesToResolvedStore(): void
    {
        $inner = $this->createMock(VectorStoreInterface::class);
        $inner->expects($this->once())
            ->method('saveMemory')
            ->with([0.1, 0.2], ['text' => 'hello']);

        $dynamic = $this->buildDynamicStore($inner, 'memory');

        $dynamic->saveMemory([0.1, 0.2], ['text' => 'hello']);
    }

    public function testSearchSimilarDelegatesToResolvedStore(): void
    {
        $inner = $this->createMock(VectorStoreInterface::class);
        $inner->expects($this->once())
            ->method('searchSimilar')
            ->with([0.1], 3, ['scope' => 'user'])
            ->willReturn([['text' => 'result']]);

        $dynamic = $this->buildDynamicStore($inner, 'memory');

        $results = $dynamic->searchSimilar([0.1], 3, ['scope' => 'user']);
        $this->assertCount(1, $results);
    }

    private function buildDynamicStore(VectorStoreInterface $inner, string $alias): DynamicVectorStore
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getVectorStore')->willReturn($alias);

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $registry = $this->createStub(VectorStoreRegistry::class);
        $registry->method('getVectorStore')->with($alias)->willReturn($inner);

        return new DynamicVectorStore($registry, $configRepo);
    }
}
