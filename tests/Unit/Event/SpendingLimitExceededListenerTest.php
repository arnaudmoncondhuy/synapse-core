<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\SpendingLimitExceededListener;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseSpendingLimitExceededEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimitLog;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class SpendingLimitExceededListenerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Cas passants — persistance
    // -------------------------------------------------------------------------

    public function testPersistsLogEntryOnEvent(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->isInstanceOf(SynapseSpendingLimitLog::class));
        $em->expects($this->once())->method('flush');

        $listener = new SpendingLimitExceededListener($em);
        $listener($this->buildEvent());
    }

    public function testLogContainsCorrectUserId(): void
    {
        $captured = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (SynapseSpendingLimitLog $log) use (&$captured) {
            $captured = $log;
        });

        (new SpendingLimitExceededListener($em))($this->buildEvent(userId: 'user-99'));

        $this->assertSame('user-99', $captured->getUserId());
    }

    public function testLogContainsCorrectScope(): void
    {
        $captured = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (SynapseSpendingLimitLog $log) use (&$captured) {
            $captured = $log;
        });

        (new SpendingLimitExceededListener($em))($this->buildEvent(scope: 'preset', scopeId: 'preset-5'));

        $this->assertSame('preset', $captured->getScope());
        $this->assertSame('preset-5', $captured->getScopeId());
    }

    public function testLogContainsCorrectAmounts(): void
    {
        $captured = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (SynapseSpendingLimitLog $log) use (&$captured) {
            $captured = $log;
        });

        (new SpendingLimitExceededListener($em))($this->buildEvent(
            limitAmount: 10.0,
            consumption: 9.5,
            estimatedCost: 1.2,
        ));

        $this->assertSame(10.0, $captured->getLimitAmount());
        $this->assertSame(9.5, $captured->getConsumption());
        $this->assertSame(1.2, $captured->getEstimatedCost());
    }

    public function testLogOverrunAmountMatchesEventCalculation(): void
    {
        $captured = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (SynapseSpendingLimitLog $log) use (&$captured) {
            $captured = $log;
        });

        $event = $this->buildEvent(limitAmount: 10.0, consumption: 9.5, estimatedCost: 1.2);

        (new SpendingLimitExceededListener($em))($event);

        $this->assertEqualsWithDelta($event->getOverrunAmount(), $captured->getOverrunAmount(), 0.0001);
    }

    public function testLogContainsCurrencyAndPeriod(): void
    {
        $captured = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (SynapseSpendingLimitLog $log) use (&$captured) {
            $captured = $log;
        });

        (new SpendingLimitExceededListener($em))($this->buildEvent(currency: 'USD', period: SpendingLimitPeriod::CALENDAR_DAY));

        $this->assertSame('USD', $captured->getCurrency());
        $this->assertSame(SpendingLimitPeriod::CALENDAR_DAY, $captured->getPeriod());
    }

    public function testExceededAtIsRecentDatetime(): void
    {
        $before = new \DateTimeImmutable();
        $captured = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (SynapseSpendingLimitLog $log) use (&$captured) {
            $captured = $log;
        });

        (new SpendingLimitExceededListener($em))($this->buildEvent());

        $after = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $captured->getExceededAt()->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $captured->getExceededAt()->getTimestamp());
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function buildEvent(
        string $userId = 'user-1',
        string $scope = 'user',
        string $scopeId = 'user-1',
        SpendingLimitPeriod $period = SpendingLimitPeriod::CALENDAR_MONTH,
        float $limitAmount = 5.0,
        float $consumption = 4.8,
        float $estimatedCost = 0.5,
        string $currency = 'EUR',
    ): SynapseSpendingLimitExceededEvent {
        return new SynapseSpendingLimitExceededEvent(
            userId: $userId,
            scope: $scope,
            scopeId: $scopeId,
            period: $period,
            limitAmount: $limitAmount,
            consumption: $consumption,
            estimatedCost: $estimatedCost,
            currency: $currency,
        );
    }
}
