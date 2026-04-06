<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\MessageHandler;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception\WorkflowExecutionException;
use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Message\ExecuteWorkflowMessage;
use ArnaudMoncondhuy\SynapseCore\MessageHandler\ExecuteWorkflowMessageHandler;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\MessageHandler\ExecuteWorkflowMessageHandler
 */
final class ExecuteWorkflowMessageHandlerTest extends TestCase
{
    public function testInvokeResolvesWorkflowAndDelegatesToRunner(): void
    {
        $workflow = $this->makeWorkflow('my_workflow');

        $repository = $this->createMock(SynapseWorkflowRepository::class);
        $repository->expects($this->once())
            ->method('findActiveByKey')
            ->with('my_workflow')
            ->willReturn($workflow);

        $runner = $this->createMock(WorkflowRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->with(
                $this->identicalTo($workflow),
                $this->callback(function (Input $input): bool {
                    return ['key' => 'value'] === $input->getStructured();
                }),
                $this->callback(function (array $options): bool {
                    return 'user-42' === ($options['user_id'] ?? null);
                }),
            )
            ->willReturn(new Output(answer: 'ok'));

        $handler = new ExecuteWorkflowMessageHandler($repository, $runner);
        $handler(new ExecuteWorkflowMessage('my_workflow', ['key' => 'value'], 'user-42'));
    }

    public function testInvokeFallsBackToMessageInputWhenStructuredEmpty(): void
    {
        $workflow = $this->makeWorkflow('wf');

        $repository = $this->createStub(SynapseWorkflowRepository::class);
        $repository->method('findActiveByKey')->willReturn($workflow);

        $runner = $this->createMock(WorkflowRunner::class);
        $runner->expects($this->once())
            ->method('run')
            ->with(
                $this->identicalTo($workflow),
                $this->callback(function (Input $input): bool {
                    return 'hello' === $input->getMessage() && [] === $input->getStructured();
                }),
                $this->anything(),
            )
            ->willReturn(new Output(answer: 'ok'));

        $handler = new ExecuteWorkflowMessageHandler($repository, $runner);
        $handler(new ExecuteWorkflowMessage('wf', [], null, 'hello'));
    }

    public function testInvokeThrowsUnrecoverableWhenWorkflowNotFound(): void
    {
        $repository = $this->createStub(SynapseWorkflowRepository::class);
        $repository->method('findActiveByKey')->willReturn(null);

        $runner = $this->createMock(WorkflowRunner::class);
        $runner->expects($this->never())->method('run');

        $handler = new ExecuteWorkflowMessageHandler($repository, $runner);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('unknown or inactive');

        $handler(new ExecuteWorkflowMessage('missing_workflow'));
    }

    public function testInvokeWrapsWorkflowExecutionExceptionAsUnrecoverable(): void
    {
        $workflow = $this->makeWorkflow('wf');

        $repository = $this->createStub(SynapseWorkflowRepository::class);
        $repository->method('findActiveByKey')->willReturn($workflow);

        $runner = $this->createStub(WorkflowRunner::class);
        $runner->method('run')->willThrowException(
            WorkflowExecutionException::stepFailed('step1', 'boom')
        );

        $handler = new ExecuteWorkflowMessageHandler($repository, $runner);

        try {
            $handler(new ExecuteWorkflowMessage('wf', ['k' => 'v']));
            $this->fail('Expected UnrecoverableMessageHandlingException');
        } catch (UnrecoverableMessageHandlingException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
            $this->assertInstanceOf(WorkflowExecutionException::class, $e->getPrevious());
        }
    }

    private function makeWorkflow(string $key): SynapseWorkflow
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey($key);
        $workflow->setName('Test workflow');
        $workflow->setDefinition(['version' => 1, 'steps' => []]);

        return $workflow;
    }
}
