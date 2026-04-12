<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Tool;

use ArnaudMoncondhuy\SynapseCore\CodeExecutor\ExecutionResult;
use ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor;
use ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseCodeExecutedEvent;
use ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool
 */
final class CodeExecuteToolTest extends TestCase
{
    public function testMetadataIsExposedForLlm(): void
    {
        $tool = $this->makeTool(new NullCodeExecutor());

        $this->assertSame('code_execute', $tool->getName());
        $this->assertNotEmpty($tool->getLabel());
        $this->assertNotEmpty($tool->getDescription());

        $schema = $tool->getInputSchema();
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('code', $schema['properties']);
        $this->assertArrayHasKey('language', $schema['properties']);
        $this->assertContains('code', $schema['required']);
    }

    public function testExecuteRejectsEmptyCode(): void
    {
        $tool = $this->makeTool(new NullCodeExecutor());

        $result = $tool->execute(['code' => '']);
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('InvalidInput', $result['error_type']);
    }

    public function testExecuteRejectsMissingCode(): void
    {
        $tool = $this->makeTool(new NullCodeExecutor());

        $result = $tool->execute([]);
        $this->assertIsArray($result);
        $this->assertFalse($result['success']);
        $this->assertSame('InvalidInput', $result['error_type']);
    }

    public function testExecuteDelegatesToExecutorAndSerializesResult(): void
    {
        $spy = new class implements CodeExecutorInterface {
            public array $calls = [];

            public function execute(string $code, string $language = 'python', array $inputs = [], array $options = []): ExecutionResult
            {
                $this->calls[] = ['code' => $code, 'language' => $language];

                return new ExecutionResult(
                    success: true,
                    stdout: "42\n",
                    returnValue: 42,
                    durationMs: 15,
                );
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedLanguages(): array
            {
                return ['python'];
            }
        };

        $tool = $this->makeTool($spy);
        $result = $tool->execute(['code' => 'result = 6 * 7', 'language' => 'python']);

        $this->assertCount(1, $spy->calls);
        $this->assertSame('result = 6 * 7', $spy->calls[0]['code']);
        $this->assertSame('python', $spy->calls[0]['language']);

        $this->assertTrue($result['success']);
        $this->assertSame("42\n", $result['stdout']);
        $this->assertSame(42, $result['return_value']);
    }

    public function testExecuteWithNullExecutorPropagatesUnavailable(): void
    {
        $tool = $this->makeTool(new NullCodeExecutor());
        $result = $tool->execute(['code' => 'print(1)']);

        $this->assertFalse($result['success']);
        $this->assertSame('BackendUnavailable', $result['error_type']);
    }

    public function testExecuteDispatchesSynapseCodeExecutedEvent(): void
    {
        $spyExec = new class implements CodeExecutorInterface {
            public function execute(string $code, string $language = 'python', array $inputs = [], array $options = []): ExecutionResult
            {
                return new ExecutionResult(
                    success: true,
                    stdout: "hello\n",
                    returnValue: 'hello',
                    durationMs: 42,
                );
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedLanguages(): array
            {
                return ['python'];
            }
        };

        $dispatchedEvents = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $tool = new CodeExecuteTool(
            $spyExec,
            $dispatcher,
            $this->createStub(EntityManagerInterface::class),
        );
        $tool->execute(['code' => 'print("hello")', 'language' => 'python']);

        $this->assertCount(1, $dispatchedEvents);
        $this->assertInstanceOf(SynapseCodeExecutedEvent::class, $dispatchedEvents[0]);
        $this->assertSame('print("hello")', $dispatchedEvents[0]->code);
        $this->assertSame('python', $dispatchedEvents[0]->language);
        $this->assertTrue($dispatchedEvents[0]->result['success']);
        $this->assertSame('hello', $dispatchedEvents[0]->result['return_value']);
    }

    public function testExecuteResultIsSerializableArray(): void
    {
        $tool = $this->makeTool(new NullCodeExecutor());
        $result = $tool->execute(['code' => 'print(1)']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
    }

    private function makeTool(CodeExecutorInterface $executor): CodeExecuteTool
    {
        return new CodeExecuteTool(
            $executor,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(EntityManagerInterface::class),
        );
    }
}
