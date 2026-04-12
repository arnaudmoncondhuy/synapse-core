<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseCodeExecutedEvent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Event\SynapseCodeExecutedEvent
 */
final class SynapseCodeExecutedEventTest extends TestCase
{
    public function testConstructorExposesAllFields(): void
    {
        $event = new SynapseCodeExecutedEvent(
            code: 'result = 1 + 2',
            language: 'python',
            result: [
                'success' => true,
                'stdout' => '',
                'stderr' => '',
                'return_value' => 3,
                'duration_ms' => 45,
                'error_type' => null,
                'error_message' => null,
            ],
        );

        $this->assertSame('result = 1 + 2', $event->code);
        $this->assertSame('python', $event->language);
        $this->assertSame(3, $event->result['return_value']);
    }

    public function testToArraySerialization(): void
    {
        $event = new SynapseCodeExecutedEvent(
            code: 'print("hi")',
            language: 'python',
            result: ['success' => true, 'stdout' => "hi\n"],
        );

        $arr = $event->toArray();
        $this->assertSame('print("hi")', $arr['code']);
        $this->assertSame('python', $arr['language']);
        $this->assertTrue($arr['result']['success']);
        $this->assertSame("hi\n", $arr['result']['stdout']);
    }

    public function testImmutability(): void
    {
        $event = new SynapseCodeExecutedEvent(code: '', language: 'python', result: []);
        $reflection = new \ReflectionClass($event);
        foreach ($reflection->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), sprintf('Property %s should be readonly', $prop->getName()));
        }
    }
}
