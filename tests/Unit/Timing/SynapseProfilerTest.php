<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Timing;

use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;

class SynapseProfilerTest extends TestCase
{
    private SynapseProfiler $profiler;

    protected function setUp(): void
    {
        $this->profiler = new SynapseProfiler();
    }

    public function testGetTimingsReturnsZeroWhenNoTimersStarted(): void
    {
        $timings = $this->profiler->getTimings();

        $this->assertSame(0, $timings['total_ms']);
        $this->assertSame([], $timings['steps']);
    }

    public function testStartAndStopRecordsStep(): void
    {
        $this->profiler->start('llm', 'call', 'LLM API call');
        $this->profiler->stop('llm', 'call', 1);

        $timings = $this->profiler->getTimings();

        $this->assertCount(1, $timings['steps']);
        $this->assertSame('call', $timings['steps'][0]['name']);
        $this->assertSame('LLM API call', $timings['steps'][0]['description']);
        $this->assertSame(1, $timings['steps'][0]['turn']);
        $this->assertGreaterThanOrEqual(0.0, $timings['steps'][0]['duration_ms']);
    }

    public function testStopWithoutStartIsIgnored(): void
    {
        $this->profiler->stop('unknown', 'timer');

        $timings = $this->profiler->getTimings();

        $this->assertSame([], $timings['steps']);
    }

    public function testResetClearsAllTimers(): void
    {
        $this->profiler->start('group', 'step1');
        $this->profiler->stop('group', 'step1');
        $this->profiler->reset();

        $timings = $this->profiler->getTimings();

        $this->assertSame(0, $timings['total_ms']);
        $this->assertSame([], $timings['steps']);
    }
}
