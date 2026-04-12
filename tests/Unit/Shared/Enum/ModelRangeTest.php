<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Enum;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use PHPUnit\Framework\TestCase;

class ModelRangeTest extends TestCase
{
    public function testFromStringValidValues(): void
    {
        $this->assertSame(ModelRange::FLAGSHIP, ModelRange::fromString('flagship'));
        $this->assertSame(ModelRange::BALANCED, ModelRange::fromString('balanced'));
        $this->assertSame(ModelRange::FAST, ModelRange::fromString('fast'));
        $this->assertSame(ModelRange::SPECIALIZED, ModelRange::fromString('specialized'));
    }

    public function testFromStringNullFallsBackToBalanced(): void
    {
        $this->assertSame(ModelRange::BALANCED, ModelRange::fromString(null));
    }

    public function testFromStringEmptyFallsBackToBalanced(): void
    {
        $this->assertSame(ModelRange::BALANCED, ModelRange::fromString(''));
    }

    public function testFromStringUnknownFallsBackToBalanced(): void
    {
        $this->assertSame(ModelRange::BALANCED, ModelRange::fromString('unknown_value'));
    }

    public function testSortPriorityOrdering(): void
    {
        $this->assertLessThan(ModelRange::FLAGSHIP->sortPriority(), ModelRange::BALANCED->sortPriority());
        $this->assertLessThan(ModelRange::FAST->sortPriority(), ModelRange::FLAGSHIP->sortPriority());
        $this->assertLessThan(ModelRange::SPECIALIZED->sortPriority(), ModelRange::FAST->sortPriority());
    }

    public function testBalancedIsFirstPriority(): void
    {
        $this->assertSame(0, ModelRange::BALANCED->sortPriority());
    }

    public function testSpecializedIsLastPriority(): void
    {
        $this->assertSame(99, ModelRange::SPECIALIZED->sortPriority());
    }

    public function testDefaultTemperatureValues(): void
    {
        $this->assertSame(0.7, ModelRange::FLAGSHIP->defaultTemperature());
        $this->assertSame(0.8, ModelRange::BALANCED->defaultTemperature());
        $this->assertSame(1.0, ModelRange::FAST->defaultTemperature());
        $this->assertSame(1.0, ModelRange::SPECIALIZED->defaultTemperature());
    }

    public function testLabelReturnsNonEmptyString(): void
    {
        foreach (ModelRange::cases() as $range) {
            $this->assertNotEmpty($range->label(), sprintf('Label vide pour %s', $range->value));
        }
    }
}
