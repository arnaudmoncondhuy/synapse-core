<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\ToolExecutionSubscriber;
use PHPUnit\Framework\TestCase;

class ToolExecutionSubscriberTest extends TestCase
{
    private ToolRegistry $toolRegistry;
    private ToolExecutionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->toolRegistry = $this->createMock(ToolRegistry::class);
        $this->subscriber = new ToolExecutionSubscriber($this->toolRegistry);
    }

    public function testExecutesToolAndRegistersResult(): void
    {
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('execute')->with(['city' => 'Paris'])->willReturn('Ensoleillé, 22°C');

        $this->toolRegistry->method('get')
            ->willReturnCallback(fn ($name) => 'get_weather' === $name ? $tool : null);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'get_weather', 'args' => ['city' => 'Paris']],
        ]);

        $this->subscriber->onToolCallRequested($event);

        $results = $event->getResults();
        $this->assertSame('Ensoleillé, 22°C', $results['get_weather']);
    }

    public function testUnknownToolReturnsNull(): void
    {
        $this->toolRegistry->method('get')->willReturn(null);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'inexistant_tool', 'args' => []],
        ]);

        $this->subscriber->onToolCallRequested($event);

        $results = $event->getResults();
        $this->assertArrayHasKey('inexistant_tool', $results);
        $this->assertNull($results['inexistant_tool']);
    }

    public function testNormalizesToolNameWithFunctionsPrefix(): void
    {
        $tool = $this->createMock(AiToolInterface::class);
        $tool->method('execute')->willReturn('ok');

        $this->toolRegistry->method('get')
            ->willReturnCallback(fn ($name) => 'my_tool' === $name ? $tool : null);

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => 'functions.my_tool', 'args' => []],
        ]);

        $this->subscriber->onToolCallRequested($event);

        $results = $event->getResults();
        $this->assertSame('ok', $results['functions.my_tool']);
    }

    public function testSkipsToolCallsWithEmptyName(): void
    {
        $this->toolRegistry->expects($this->never())->method('get');

        $event = new SynapseToolCallRequestedEvent([
            ['id' => 'call_1', 'name' => '', 'args' => []],
        ]);

        $this->subscriber->onToolCallRequested($event);

        $this->assertEmpty($event->getResults());
    }
}
