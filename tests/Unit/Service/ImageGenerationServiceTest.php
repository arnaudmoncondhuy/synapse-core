<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseCore\Contract\ImageGenerationClientInterface;
use ArnaudMoncondhuy\SynapseCore\Service\ImageGenerationService;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\GeneratedImage;
use PHPUnit\Framework\TestCase;

class ImageGenerationServiceTest extends TestCase
{
    public function testGenerateWithExplicitProvider(): void
    {
        $image = new GeneratedImage('base64data', 'image/png');

        $client = $this->createMock(ImageGenerationClientInterface::class);
        $client->method('getProviderName')->willReturn('ovh');
        $client->expects($this->once())
            ->method('generateImage')
            ->with('A cat', ['model' => 'flux'])
            ->willReturn([$image]);

        $service = new ImageGenerationService(new \ArrayIterator([$client]));
        $result = $service->generate('A cat', 'ovh', ['model' => 'flux']);

        $this->assertCount(1, $result);
        $this->assertSame('base64data', $result[0]->data);
    }

    public function testGenerateWithNullProviderUsesFirst(): void
    {
        $client = $this->createMock(ImageGenerationClientInterface::class);
        $client->method('getProviderName')->willReturn('default');
        $client->expects($this->once())
            ->method('generateImage')
            ->willReturn([]);

        $service = new ImageGenerationService(new \ArrayIterator([$client]));
        $service->generate('A dog');

        $this->assertTrue(true);
    }

    public function testGenerateThrowsWhenNoClientsAvailable(): void
    {
        $service = new ImageGenerationService(new \ArrayIterator([]));

        $this->expectException(\RuntimeException::class);
        $service->generate('A bird');
    }

    public function testGenerateThrowsForUnknownProvider(): void
    {
        $client = $this->createStub(ImageGenerationClientInterface::class);
        $client->method('getProviderName')->willReturn('ovh');

        $service = new ImageGenerationService(new \ArrayIterator([$client]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');
        $service->generate('A fish', 'openai');
    }

    public function testGetAvailableProviders(): void
    {
        $c1 = $this->createStub(ImageGenerationClientInterface::class);
        $c1->method('getProviderName')->willReturn('ovh');
        $c2 = $this->createStub(ImageGenerationClientInterface::class);
        $c2->method('getProviderName')->willReturn('google');

        $service = new ImageGenerationService(new \ArrayIterator([$c1, $c2]));

        $this->assertSame(['ovh', 'google'], $service->getAvailableProviders());
    }
}
