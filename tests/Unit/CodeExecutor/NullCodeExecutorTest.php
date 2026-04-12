<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\CodeExecutor;

use ArnaudMoncondhuy\SynapseCore\CodeExecutor\ExecutionResult;
use ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor
 * @covers \ArnaudMoncondhuy\SynapseCore\CodeExecutor\ExecutionResult
 */
final class NullCodeExecutorTest extends TestCase
{
    public function testExecuteAlwaysReturnsBackendUnavailable(): void
    {
        $exec = new NullCodeExecutor();
        $result = $exec->execute('print("hello")');

        $this->assertInstanceOf(ExecutionResult::class, $result);
        $this->assertFalse($result->success);
        $this->assertSame('BackendUnavailable', $result->errorType);
        $this->assertStringContainsString('disabled', $result->errorMessage ?? '');
        $this->assertSame('', $result->stdout);
        $this->assertSame('', $result->stderr);
    }

    public function testIsAvailableIsFalse(): void
    {
        $this->assertFalse((new NullCodeExecutor())->isAvailable());
    }

    public function testSupportedLanguagesIsEmpty(): void
    {
        $this->assertSame([], (new NullCodeExecutor())->getSupportedLanguages());
    }

    public function testExecutionResultBackendUnavailableFactory(): void
    {
        $r = ExecutionResult::backendUnavailable('no backend');
        $this->assertFalse($r->success);
        $this->assertSame('BackendUnavailable', $r->errorType);
        $this->assertSame('no backend', $r->errorMessage);
    }

    public function testExecutionResultUnsupportedLanguageFactory(): void
    {
        $r = ExecutionResult::unsupportedLanguage('rust');
        $this->assertFalse($r->success);
        $this->assertSame('UnsupportedLanguage', $r->errorType);
        $this->assertStringContainsString('rust', (string) $r->errorMessage);
    }

    public function testToArraySerialization(): void
    {
        $r = new ExecutionResult(
            success: true,
            stdout: "42\n",
            stderr: '',
            returnValue: 42,
            durationMs: 123,
        );

        $arr = $r->toArray();
        $this->assertTrue($arr['success']);
        $this->assertSame("42\n", $arr['stdout']);
        $this->assertSame(42, $arr['return_value']);
        $this->assertSame(123, $arr['duration_ms']);
        $this->assertNull($arr['error_type']);
    }
}
