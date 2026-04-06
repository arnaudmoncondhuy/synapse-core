<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimitLog;
use PHPUnit\Framework\TestCase;

class SynapseSpendingLimitLogTest extends TestCase
{
    public function testConstructorSetsAllFields(): void
    {
        $log = new SynapseSpendingLimitLog(
            userId: 'user@example.com',
            scope: 'user',
            scopeId: 'user-42',
            period: SpendingLimitPeriod::CALENDAR_MONTH,
            limitAmount: 10.5,
            consumption: 9.8,
            estimatedCost: 1.2,
            overrunAmount: 0.5,
            currency: 'EUR',
        );

        $this->assertNull($log->getId());
        $this->assertSame('user@example.com', $log->getUserId());
        $this->assertSame('user', $log->getScope());
        $this->assertSame('user-42', $log->getScopeId());
        $this->assertSame(SpendingLimitPeriod::CALENDAR_MONTH, $log->getPeriod());
        $this->assertSame(10.5, $log->getLimitAmount());
        $this->assertSame(9.8, $log->getConsumption());
        $this->assertSame(1.2, $log->getEstimatedCost());
        $this->assertSame(0.5, $log->getOverrunAmount());
        $this->assertSame('EUR', $log->getCurrency());
        $this->assertInstanceOf(\DateTimeImmutable::class, $log->getExceededAt());
    }

    public function testFloatConversion(): void
    {
        $log = new SynapseSpendingLimitLog(
            userId: 'admin',
            scope: 'preset',
            scopeId: 'preset-1',
            period: SpendingLimitPeriod::SLIDING_DAY,
            limitAmount: 0.0,
            consumption: 0.0,
            estimatedCost: 0.0,
            overrunAmount: 0.0,
            currency: 'USD',
        );

        $this->assertSame(0.0, $log->getLimitAmount());
        $this->assertSame(0.0, $log->getConsumption());
        $this->assertSame(0.0, $log->getEstimatedCost());
        $this->assertSame(0.0, $log->getOverrunAmount());
    }

    public function testExceededAtIsSetAutomatically(): void
    {
        $before = new \DateTimeImmutable();
        $log = new SynapseSpendingLimitLog('u', 's', 'sid', SpendingLimitPeriod::CALENDAR_DAY, 1.0, 0.5, 0.6, 0.1, 'EUR');
        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $log->getExceededAt());
        $this->assertLessThanOrEqual($after, $log->getExceededAt());
    }
}
