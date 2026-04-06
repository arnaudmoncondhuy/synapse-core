<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry
 */
final class CodeAgentRegistryTest extends TestCase
{
    public function testGetReturnsRegisteredAgentByName(): void
    {
        $foo = $this->makeAgent('foo_agent');
        $bar = $this->makeAgent('bar_agent');

        $registry = new CodeAgentRegistry([$foo, $bar]);

        $this->assertSame($foo, $registry->get('foo_agent'));
        $this->assertSame($bar, $registry->get('bar_agent'));
        $this->assertTrue($registry->has('foo_agent'));
    }

    public function testGetReturnsNullForUnknownName(): void
    {
        $registry = new CodeAgentRegistry([$this->makeAgent('foo_agent')]);

        $this->assertNull($registry->get('missing'));
        $this->assertFalse($registry->has('missing'));
    }

    public function testAllReturnsAgentsIndexedByName(): void
    {
        $foo = $this->makeAgent('foo_agent');
        $bar = $this->makeAgent('bar_agent');

        $registry = new CodeAgentRegistry([$foo, $bar]);

        $this->assertSame(
            ['foo_agent' => $foo, 'bar_agent' => $bar],
            $registry->all(),
        );
        $this->assertSame(['foo_agent', 'bar_agent'], $registry->names());
    }

    public function testDuplicateNameThrowsAtBoot(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate code agent name "dup"');

        new CodeAgentRegistry([
            $this->makeAgent('dup'),
            $this->makeAgent('dup'),
        ]);
    }

    private function makeAgent(string $name): AgentInterface
    {
        return new class($name) implements AgentInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'test agent';
            }

            public function call(Input $input, array $options = []): Output
            {
                return Output::ofData(['echo' => $input->getMessage()]);
            }
        };
    }
}
