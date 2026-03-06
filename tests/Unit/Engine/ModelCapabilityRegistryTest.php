<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class ModelCapabilityRegistryTest extends TestCase
{
    public function testGetCapabilitiesReturnsSomethingValid(): void
    {
        $registry = new ModelCapabilityRegistry();
        $capabilities = $registry->getCapabilities('non-existent-model');

        $this->assertInstanceOf(ModelCapabilities::class, $capabilities);
        $this->assertSame('non-existent-model', $capabilities->model);
        // Defaults
        $this->assertTrue($capabilities->streaming);
    }

    public function testIsKnownModel(): void
    {
        $registry = new ModelCapabilityRegistry();
        $this->assertIsBool($registry->isKnownModel('anything'));
    }
}
