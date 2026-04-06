<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Rag;

use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderFactoryInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use PHPUnit\Framework\TestCase;

class RagSourceRegistryTest extends TestCase
{
    public function testGetReturnsStaticProvider(): void
    {
        $provider = $this->createStub(RagSourceProviderInterface::class);
        $provider->method('getSlug')->willReturn('google_drive');

        $registry = new RagSourceRegistry(new \ArrayIterator([$provider]), new \ArrayIterator([]));

        $this->assertSame($provider, $registry->get('google_drive'));
    }

    public function testGetReturnsNullWhenNotFound(): void
    {
        $registry = new RagSourceRegistry(new \ArrayIterator([]), new \ArrayIterator([]));

        $this->assertNull($registry->get('nonexistent'));
    }

    public function testGetLoadsFromFactoryLazily(): void
    {
        $dynamic = $this->createStub(RagSourceProviderInterface::class);
        $dynamic->method('getSlug')->willReturn('custom_source');

        $factory = $this->createStub(RagSourceProviderFactoryInterface::class);
        $factory->method('createProviders')->willReturn([$dynamic]);

        $registry = new RagSourceRegistry(new \ArrayIterator([]), new \ArrayIterator([$factory]));

        $this->assertSame($dynamic, $registry->get('custom_source'));
    }

    public function testStaticProvidersHavePriorityOverDynamic(): void
    {
        $static = $this->createStub(RagSourceProviderInterface::class);
        $static->method('getSlug')->willReturn('shared');

        $dynamic = $this->createStub(RagSourceProviderInterface::class);
        $dynamic->method('getSlug')->willReturn('shared');

        $factory = $this->createStub(RagSourceProviderFactoryInterface::class);
        $factory->method('createProviders')->willReturn([$dynamic]);

        $registry = new RagSourceRegistry(new \ArrayIterator([$static]), new \ArrayIterator([$factory]));

        $this->assertSame($static, $registry->get('shared'));
    }

    public function testHasReturnsTrueForExisting(): void
    {
        $provider = $this->createStub(RagSourceProviderInterface::class);
        $provider->method('getSlug')->willReturn('test');

        $registry = new RagSourceRegistry(new \ArrayIterator([$provider]), new \ArrayIterator([]));

        $this->assertTrue($registry->has('test'));
        $this->assertFalse($registry->has('unknown'));
    }

    public function testGetAllReturnsBothStaticAndDynamic(): void
    {
        $static = $this->createStub(RagSourceProviderInterface::class);
        $static->method('getSlug')->willReturn('static_source');

        $dynamic = $this->createStub(RagSourceProviderInterface::class);
        $dynamic->method('getSlug')->willReturn('dynamic_source');

        $factory = $this->createStub(RagSourceProviderFactoryInterface::class);
        $factory->method('createProviders')->willReturn([$dynamic]);

        $registry = new RagSourceRegistry(new \ArrayIterator([$static]), new \ArrayIterator([$factory]));

        $all = $registry->getAll();
        $this->assertCount(2, $all);
        $this->assertArrayHasKey('static_source', $all);
        $this->assertArrayHasKey('dynamic_source', $all);
    }

    public function testFactoriesLoadedOnlyOnce(): void
    {
        $factory = $this->createMock(RagSourceProviderFactoryInterface::class);
        $factory->expects($this->once())->method('createProviders')->willReturn([]);

        $registry = new RagSourceRegistry(new \ArrayIterator([]), new \ArrayIterator([$factory]));

        $registry->getAll();
        $registry->getAll();
    }
}
