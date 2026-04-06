<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseCore\Contract\EmbeddingClientInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class EmbeddingServiceTest extends TestCase
{
    public function testGenerateEmbeddingsWithConfiguredProvider(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getEmbeddingProvider')->willReturn('google_vertex_ai');
        $config->method('getEmbeddingModel')->willReturn('text-embedding-004');
        $config->method('getEmbeddingDimension')->willReturn(256);

        $client = $this->createMock(EmbeddingLlmClient::class);
        $client->method('getProviderName')->willReturn('google_vertex_ai');
        $client->expects($this->once())
            ->method('generateEmbeddings')
            ->with('hello', 'text-embedding-004', ['output_dimensionality' => 256])
            ->willReturn([
                'embeddings' => [[0.1, 0.2]],
                'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
            ]);

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())->method('dispatch');

        $service = new EmbeddingService(
            new \ArrayIterator([$client]),
            $this->createStub(SynapseProviderRepository::class),
            $this->createStub(ModelCapabilityRegistry::class),
            $dispatcher,
            $configRepo,
        );

        $result = $service->generateEmbeddings('hello');

        $this->assertSame([[0.1, 0.2]], $result['embeddings']);
    }

    public function testGenerateEmbeddingsThrowsWhenNoProvider(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getEmbeddingProvider')->willReturn(null);

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $providerRepo = $this->createStub(SynapseProviderRepository::class);
        $providerRepo->method('findAll')->willReturn([]);

        $service = new EmbeddingService(
            new \ArrayIterator([]),
            $providerRepo,
            $this->createStub(ModelCapabilityRegistry::class),
            $this->createStub(EventDispatcherInterface::class),
            $configRepo,
        );

        $this->expectException(\RuntimeException::class);
        $service->generateEmbeddings('hello');
    }

    public function testGenerateEmbeddingsThrowsWhenClientMissing(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getEmbeddingProvider')->willReturn('nonexistent');

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $service = new EmbeddingService(
            new \ArrayIterator([]),
            $this->createStub(SynapseProviderRepository::class),
            $this->createStub(ModelCapabilityRegistry::class),
            $this->createStub(EventDispatcherInterface::class),
            $configRepo,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/n'existe pas/");
        $service->generateEmbeddings('hello');
    }

    public function testAliasResolution(): void
    {
        $config = $this->createStub(SynapseConfig::class);
        $config->method('getEmbeddingProvider')->willReturn('gemini');
        $config->method('getEmbeddingModel')->willReturn('model');
        $config->method('getEmbeddingDimension')->willReturn(null);

        $client = $this->createMock(EmbeddingLlmClient::class);
        $client->method('getProviderName')->willReturn('google_vertex_ai');
        $client->expects($this->once())
            ->method('generateEmbeddings')
            ->willReturn([
                'embeddings' => [[0.5]],
                'usage' => ['prompt_tokens' => 1, 'total_tokens' => 1],
            ]);

        $configRepo = $this->createStub(SynapseConfigRepository::class);
        $configRepo->method('getGlobalConfig')->willReturn($config);

        $service = new EmbeddingService(
            new \ArrayIterator([$client]),
            $this->createStub(SynapseProviderRepository::class),
            $this->createStub(ModelCapabilityRegistry::class),
            $this->createStub(EventDispatcherInterface::class),
            $configRepo,
        );

        $result = $service->generateEmbeddings('test');
        $this->assertNotEmpty($result['embeddings']);
    }
}

/**
 * Combined interface for mocking a client that implements both LlmClient and EmbeddingClient.
 */
interface EmbeddingLlmClient extends LlmClientInterface, EmbeddingClientInterface
{
}
