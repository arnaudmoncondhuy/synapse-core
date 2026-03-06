<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\LlmClientRegistry;
use PHPUnit\Framework\TestCase;

class LlmClientRegistryTest extends TestCase
{
    private $configProvider;
    private $client1;
    private $client2;

    protected function setUp(): void
    {
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);
        $this->client1 = $this->createMock(LlmClientInterface::class);
        $this->client1->method('getProviderName')->willReturn('gemini');
        $this->client2 = $this->createMock(LlmClientInterface::class);
        $this->client2->method('getProviderName')->willReturn('openai');
    }

    public function testGetClientFromConfig(): void
    {
        $registry = new LlmClientRegistry([$this->client1, $this->client2], $this->configProvider, 'gemini');

        $this->configProvider->method('getConfig')->willReturn(['provider' => 'openai']);

        $this->assertSame($this->client2, $registry->getClient());
    }

    public function testGetClientDefaultFallback(): void
    {
        $registry = new LlmClientRegistry([$this->client1, $this->client2], $this->configProvider, 'gemini');

        $this->configProvider->method('getConfig')->willReturn([]);

        $this->assertSame($this->client1, $registry->getClient());
    }

    public function testGetClientThrowsExceptionOnUnknownProvider(): void
    {
        $registry = new LlmClientRegistry([$this->client1], $this->configProvider, 'gemini');

        $this->configProvider->method('getConfig')->willReturn(['provider' => 'unknown']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Provider LLM "unknown" non disponible.');

        $registry->getClient();
    }

    public function testGetAvailableProviders(): void
    {
        $registry = new LlmClientRegistry([$this->client1, $this->client2], $this->configProvider);
        $this->assertEqualsCanonicalizing(['gemini', 'openai'], $registry->getAvailableProviders());
    }
}
