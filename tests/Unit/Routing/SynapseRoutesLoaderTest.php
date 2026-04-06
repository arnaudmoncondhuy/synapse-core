<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Routing;

use ArnaudMoncondhuy\SynapseCore\Routing\SynapseRoutesLoader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

class SynapseRoutesLoaderTest extends TestCase
{
    public function testSupportsSynapseType(): void
    {
        $kernel = $this->createStub(KernelInterface::class);
        $loader = new SynapseRoutesLoader($kernel);

        $this->assertTrue($loader->supports('.', 'synapse'));
        $this->assertFalse($loader->supports('.', 'yaml'));
        $this->assertFalse($loader->supports('.', null));
    }
}
