<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseCore\Chunking\TextSplitterRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\TextSplitterInterface;
use ArnaudMoncondhuy\SynapseCore\Service\ChunkingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use PHPUnit\Framework\TestCase;

class ChunkingServiceTest extends TestCase
{
    public function testChunkTextUsesConfigDefaults(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getChunkingStrategy')->willReturn('recursive');
        $config->method('getChunkSize')->willReturn(500);
        $config->method('getChunkOverlap')->willReturn(50);

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $splitter = $this->createMock(TextSplitterInterface::class);
        $splitter->expects($this->once())
            ->method('splitText')
            ->with('Hello world', 500, 50)
            ->willReturn(['Hello', 'world']);

        $registry = $this->createStub(TextSplitterRegistry::class);
        $registry->method('getSplitter')->with('recursive')->willReturn($splitter);

        $service = new ChunkingService($registry, $configRepo);
        $result = $service->chunkText('Hello world');

        $this->assertSame(['Hello', 'world'], $result);
    }

    public function testChunkTextOverridesParameters(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getChunkingStrategy')->willReturn('recursive');
        $config->method('getChunkSize')->willReturn(500);
        $config->method('getChunkOverlap')->willReturn(50);

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $splitter = $this->createMock(TextSplitterInterface::class);
        $splitter->expects($this->once())
            ->method('splitText')
            ->with('text', 200, 20)
            ->willReturn(['chunk']);

        $registry = $this->createStub(TextSplitterRegistry::class);
        $registry->method('getSplitter')->with('fixed')->willReturn($splitter);

        $service = new ChunkingService($registry, $configRepo);
        $result = $service->chunkText('text', 200, 20, 'fixed');

        $this->assertSame(['chunk'], $result);
    }

    public function testGetSplitterReturnsConfiguredSplitter(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getChunkingStrategy')->willReturn('fixed');

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $splitter = $this->createStub(TextSplitterInterface::class);
        $registry = $this->createStub(TextSplitterRegistry::class);
        $registry->method('getSplitter')->with('fixed')->willReturn($splitter);

        $service = new ChunkingService($registry, $configRepo);

        $this->assertSame($splitter, $service->getSplitter());
    }
}
