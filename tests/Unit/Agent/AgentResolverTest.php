<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Agent\ConfiguredAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\Exception\AgentDepthExceededException;
use ArnaudMoncondhuy\SynapseCore\Agent\Exception\AgentNotFoundException;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Event\AgentDepthLimitReachedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver
 */
final class AgentResolverTest extends TestCase
{
    public function testResolveReturnsCodeAgentWhenFound(): void
    {
        $codeAgent = $this->makeCodeAgent('foo');
        $registry = new CodeAgentRegistry([$codeAgent]);

        $configAgents = $this->createMock(AgentRegistry::class);
        $configAgents->method('get')->willReturn(null);

        $resolver = new AgentResolver(
            $registry,
            $configAgents,
            $this->createMock(ChatService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 2,
        );

        $resolved = $resolver->resolve('foo', AgentContext::root());

        $this->assertSame($codeAgent, $resolved);
    }

    public function testResolveWrapsConfigAgentInConfiguredAgent(): void
    {
        $registry = new CodeAgentRegistry([]);

        $entity = new SynapseAgent();
        $entity->setKey('support_client');
        $entity->setName('X');
        $entity->setDescription('X');

        $configAgents = $this->createMock(AgentRegistry::class);
        $configAgents->method('get')->with('support_client')->willReturn($entity);

        $resolver = new AgentResolver(
            $registry,
            $configAgents,
            $this->createMock(ChatService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 2,
        );

        $resolved = $resolver->resolve('support_client', AgentContext::root());

        $this->assertInstanceOf(ConfiguredAgent::class, $resolved);
        $this->assertSame($entity, $resolved->getEntity());
    }

    public function testCodeAgentWinsOverConfigAgentOnCollision(): void
    {
        $codeAgent = $this->makeCodeAgent('duplicated');
        $registry = new CodeAgentRegistry([$codeAgent]);

        $entity = new SynapseAgent();
        $entity->setKey('duplicated');
        $entity->setName('X');
        $entity->setDescription('X');

        $configAgents = $this->createMock(AgentRegistry::class);
        $configAgents->method('get')->with('duplicated')->willReturn($entity);

        $resolver = new AgentResolver(
            $registry,
            $configAgents,
            $this->createMock(ChatService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 2,
        );

        $resolved = $resolver->resolve('duplicated', AgentContext::root());

        $this->assertSame($codeAgent, $resolved, 'code agent must win over config on key collision');
    }

    public function testResolveThrowsWhenAgentNotFound(): void
    {
        $registry = new CodeAgentRegistry([]);

        $configAgents = $this->createMock(AgentRegistry::class);
        $configAgents->method('get')->willReturn(null);

        $resolver = new AgentResolver(
            $registry,
            $configAgents,
            $this->createMock(ChatService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 2,
        );

        $this->expectException(AgentNotFoundException::class);
        $resolver->resolve('missing', AgentContext::root());
    }

    public function testResolveRejectsCallsBeyondMaxDepth(): void
    {
        $registry = new CodeAgentRegistry([$this->makeCodeAgent('foo')]);

        $configAgents = $this->createMock(AgentRegistry::class);
        $configAgents->method('get')->willReturn(null);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(AgentDepthLimitReachedEvent::class))
            ->willReturnArgument(0);

        $resolver = new AgentResolver(
            $registry,
            $configAgents,
            $this->createMock(ChatService::class),
            $dispatcher,
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 2,
        );

        // Build a context at depth = maxDepth (which already fails the check).
        $root = AgentContext::root(maxDepth: 2);
        $child1 = $root->createChild('run-1');
        $child2 = $child1->createChild('run-2');

        $this->assertTrue($child2->isDepthExceeded());

        $this->expectException(AgentDepthExceededException::class);
        $resolver->resolve('foo', $child2);
    }

    public function testCreateRootContextUsesConfiguredMaxDepth(): void
    {
        $resolver = new AgentResolver(
            new CodeAgentRegistry([]),
            $this->createMock(AgentRegistry::class),
            $this->createMock(ChatService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 5,
        );

        $ctx = $resolver->createRootContext(userId: 'u1');

        $this->assertSame(0, $ctx->getDepth());
        $this->assertSame(5, $ctx->getMaxDepth());
        $this->assertSame('u1', $ctx->getUserId());
        $this->assertSame(5, $resolver->getMaxDepth());
    }

    public function testHasDoesNotCheckDepth(): void
    {
        $registry = new CodeAgentRegistry([$this->makeCodeAgent('foo')]);

        $configAgents = $this->createMock(AgentRegistry::class);
        $configAgents->method('get')->willReturn(null);

        $resolver = new AgentResolver(
            $registry,
            $configAgents,
            $this->createMock(ChatService::class),
            $this->createMock(EventDispatcherInterface::class),
            $this->createMock(WorkflowRunner::class),
            $this->createMock(SynapseWorkflowRepository::class),
            maxDepth: 2,
        );

        $this->assertTrue($resolver->has('foo'));
        $this->assertFalse($resolver->has('bar'));
    }

    private function makeCodeAgent(string $name): AgentInterface
    {
        return new class($name) implements AgentInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
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
