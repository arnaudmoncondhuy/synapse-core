<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner
 */
final class WorkflowRunnerTest extends TestCase
{
    public function testRunPersistsRunAndFlushesOnSuccess(): void
    {
        $spy = $this->makeSpyAgent('echo', fn (Input $i) => new Output(answer: 'ok', usage: ['prompt_tokens' => 3, 'completion_tokens' => 2, 'total_tokens' => 5]));
        $resolver = $this->makeResolver([$spy]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [['name' => 's1', 'agent_name' => 'echo']],
            'outputs' => ['answer' => '$.steps.s1.output.text'],
        ]);

        $persisted = null;
        $flushCount = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($arg) use (&$persisted): bool {
                $this->assertInstanceOf(SynapseWorkflowRun::class, $arg);
                $persisted = $arg;

                return true;
            }));
        $em->expects($this->exactly(2))
            ->method('flush')
            ->willReturnCallback(function () use (&$flushCount): void {
                ++$flushCount;
            });

        $runner = new WorkflowRunner($em, $resolver);
        $output = $runner->run($workflow, Input::ofMessage('hi'));

        $this->assertSame('ok', $output->getData()['answer'] ?? null);
        $this->assertSame(2, $flushCount);
        $this->assertInstanceOf(SynapseWorkflowRun::class, $persisted);
        $this->assertSame(WorkflowRunStatus::COMPLETED, $persisted->getStatus());
        $this->assertSame('mon_workflow', $persisted->getWorkflowKey());
        $this->assertSame(1, $persisted->getWorkflowVersion());
    }

    public function testRunFlushesFailedStateOnException(): void
    {
        $failing = $this->makeSpyAgent('bad', function (): Output {
            throw new \RuntimeException('kaboom');
        });
        $resolver = $this->makeResolver([$failing]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [['name' => 's1', 'agent_name' => 'bad']],
        ]);

        $persisted = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function ($arg) use (&$persisted): bool {
                $persisted = $arg;

                return true;
            }));
        $em->expects($this->exactly(2))->method('flush');

        $runner = new WorkflowRunner($em, $resolver);

        try {
            $runner->run($workflow, Input::ofMessage('x'));
            $this->fail('Expected WorkflowExecutionException');
        } catch (WorkflowExecutionException) {
            // attendu
        }

        $this->assertInstanceOf(SynapseWorkflowRun::class, $persisted);
        $this->assertSame(WorkflowRunStatus::FAILED, $persisted->getStatus());
        $this->assertNotNull($persisted->getErrorMessage());
        $this->assertNotNull($persisted->getCompletedAt());
    }

    public function testRunPropagatesUserIdFromOptions(): void
    {
        $spy = $this->makeSpyAgent('echo', fn () => new Output(answer: 'ok'));
        $resolver = $this->makeResolver([$spy]);

        $workflow = $this->makeWorkflow([
            'version' => 1,
            'steps' => [['name' => 's1', 'agent_name' => 'echo']],
        ]);

        $persisted = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function ($arg) use (&$persisted): void {
            $persisted = $arg;
        });

        (new WorkflowRunner($em, $resolver))->run(
            $workflow,
            Input::ofMessage('x'),
            ['user_id' => 'alice-42'],
        );

        $this->assertInstanceOf(SynapseWorkflowRun::class, $persisted);
        $this->assertSame('alice-42', $persisted->getUserId());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param callable(Input, array<string, mixed>): Output $handler
     */
    private function makeSpyAgent(string $name, callable $handler): AgentInterface
    {
        return new class($name, $handler) implements AgentInterface {
            /**
             * @param callable(Input, array<string, mixed>): Output $handler
             */
            public function __construct(
                private readonly string $name,
                private readonly mixed $handler,
            ) {
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
                return 'spy agent';
            }

            public function call(Input $input, array $options = []): Output
            {
                return ($this->handler)($input, $options);
            }
        };
    }

    /**
     * @param list<AgentInterface> $agents
     */
    private function makeResolver(array $agents): AgentResolver
    {
        $configAgents = $this->createStub(AgentRegistry::class);
        $configAgents->method('get')->willReturn(null);

        return new AgentResolver(
            new CodeAgentRegistry($agents),
            $configAgents,
            $this->createStub(ChatService::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(WorkflowRunner::class),
            $this->createStub(SynapseWorkflowRepository::class),
            maxDepth: 5,
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function makeWorkflow(array $definition): SynapseWorkflow
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('mon_workflow');
        $workflow->setName('Mon workflow');
        $workflow->setDefinition($definition);

        return $workflow;
    }
}
