<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Engine;

use ArnaudMoncondhuy\SynapseCore\Engine\ToolExecutor;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class ToolExecutorTest extends TestCase
{
    private EventDispatcherInterface $dispatcher;
    private SynapseProfiler $profiler;
    private ToolExecutor $executor;

    protected function setUp(): void
    {
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->profiler = $this->createMock(SynapseProfiler::class);
        $this->executor = new ToolExecutor($this->dispatcher, $this->profiler);
    }

    public function testInjectsToolResultIntoPrompt(): void
    {
        $toolCalls = [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'my_tool', 'arguments' => '{"key":"val"}'],
        ]];

        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof SynapseToolCallRequestedEvent) {
                $event->setToolResult('my_tool', 'résultat outil');
            }

            return $event;
        });

        $prompt = ['contents' => []];
        $this->executor->execute($prompt, $toolCalls, 0);

        $this->assertCount(1, $prompt['contents']);
        $this->assertSame('tool', $prompt['contents'][0]['role']);
        $this->assertSame('résultat outil', $prompt['contents'][0]['content']);
        $this->assertSame('call_1', $prompt['contents'][0]['tool_call_id']);
    }

    public function testNullResultStillAddsToolMessage(): void
    {
        $toolCalls = [[
            'id' => 'call_abc',
            'type' => 'function',
            'function' => ['name' => 'unknown_tool', 'arguments' => '{}'],
        ]];

        $this->dispatcher->method('dispatch')->willReturnArgument(0);

        $prompt = ['contents' => []];
        $this->executor->execute($prompt, $toolCalls, 0);

        // Un message role:tool doit être ajouté même si l'outil est inconnu (null résultat)
        $this->assertCount(1, $prompt['contents']);
        $this->assertSame('tool', $prompt['contents'][0]['role']);
        $this->assertSame('', $prompt['contents'][0]['content']);
    }

    public function testDispatchesCompletedEventWhenResultNotNull(): void
    {
        $toolCalls = [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'my_tool', 'arguments' => '{}'],
        ]];

        $completedDispatched = false;
        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$completedDispatched) {
            if ($event instanceof SynapseToolCallRequestedEvent) {
                $event->setToolResult('my_tool', 'ok');
            }
            if ($event instanceof SynapseToolCallCompletedEvent) {
                $completedDispatched = true;
            }

            return $event;
        });

        $prompt = ['contents' => []];
        $this->executor->execute($prompt, $toolCalls, 0);

        $this->assertTrue($completedDispatched);
    }

    public function testDoesNotDispatchCompletedEventWhenResultNull(): void
    {
        $toolCalls = [[
            'id' => 'call_1',
            'type' => 'function',
            'function' => ['name' => 'unknown_tool', 'arguments' => '{}'],
        ]];

        $completedDispatched = false;
        $this->dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$completedDispatched) {
            if ($event instanceof SynapseToolCallCompletedEvent) {
                $completedDispatched = true;
            }

            return $event;
        });

        $prompt = ['contents' => []];
        $this->executor->execute($prompt, $toolCalls, 0);

        $this->assertFalse($completedDispatched);
    }
}
