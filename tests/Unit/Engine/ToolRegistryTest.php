<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use PHPUnit\Framework\TestCase;

class ToolRegistryTest extends TestCase
{
    public function testToolRegistryOperations(): void
    {
        $tool1 = $this->createMock(AiToolInterface::class);
        $tool1->method('getName')->willReturn('calc');
        $tool1->method('getDescription')->willReturn('Calculator');
        $tool1->method('getInputSchema')->willReturn(['type' => 'object']);

        $tool2 = $this->createMock(AiToolInterface::class);
        $tool2->method('getName')->willReturn('search');

        $registry = new ToolRegistry([$tool1, $tool2]);

        $this->assertTrue($registry->has('calc'));
        $this->assertTrue($registry->has('search'));
        $this->assertFalse($registry->has('unknown'));

        $this->assertSame($tool1, $registry->get('calc'));
        $this->assertCount(2, $registry->getTools());

        $definitions = $registry->getDefinitions(['calc']);
        $this->assertCount(1, $definitions);
        $this->assertSame('calc', $definitions[0]['name']);
        $this->assertSame('Calculator', $definitions[0]['description']);
    }
}
