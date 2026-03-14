<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Accounting;

use ArnaudMoncondhuy\SynapseCore\Accounting\SpendingLimitChecker;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseSpendingLimitExceededEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SpendingLimitCheckerTest extends TestCase
{
    private SynapseLlmCallRepository $tokenUsageRepo;
    private SynapseSpendingLimitRepository $spendingLimitRepo;
    private SynapseConfigRepository $configRepo;
    private SynapseConfig $config;

    protected function setUp(): void
    {
        $this->tokenUsageRepo = $this->createStub(SynapseLlmCallRepository::class);
        $this->spendingLimitRepo = $this->createStub(SynapseSpendingLimitRepository::class);
        $this->configRepo = $this->createStub(SynapseConfigRepository::class);

        $this->config = new SynapseConfig();
        $this->config->setSpendingLimitsEnabled(true);

        $this->configRepo->method('getGlobalConfig')->willReturn($this->config);

        $this->spendingLimitRepo->method('findForPreset')->willReturn([]);
        $this->spendingLimitRepo->method('findForAgent')->willReturn([]);
    }

    // -------------------------------------------------------------------------
    // Cas passants
    // -------------------------------------------------------------------------

    public function testPassesWhenNoBudgetDefined(): void
    {
        $this->spendingLimitRepo->method('findForUser')->willReturn([]);

        $checker = $this->buildChecker();
        $checker->assertCanSpend('user-1', null, 1.0);

        $this->expectNotToPerformAssertions();
    }

    public function testPassesWhenConsumptionBelowLimit(): void
    {
        $limit = $this->buildLimit(amount: 10.0, consumption: 5.0);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $checker = $this->buildChecker();
        $checker->assertCanSpend('user-1', null, 2.0); // 5.0 + 2.0 = 7.0 < 10.0

        $this->expectNotToPerformAssertions();
    }

    public function testPassesWhenSpendingLimitsDisabled(): void
    {
        $this->config->setSpendingLimitsEnabled(false);

        $limit = $this->buildLimit(amount: 1.0, consumption: 0.5);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $checker = $this->buildChecker();
        $checker->assertCanSpend('user-1', null, 99.0); // dépasserait, mais désactivé

        $this->expectNotToPerformAssertions();
    }

    // -------------------------------------------------------------------------
    // Cas bloquants — exception
    // -------------------------------------------------------------------------

    public function testThrowsWhenLimitExceeded(): void
    {
        $limit = $this->buildLimit(amount: 5.0, consumption: 4.5);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $checker = $this->buildChecker();

        $this->expectException(LlmQuotaException::class);
        $checker->assertCanSpend('user-1', null, 1.0); // 4.5 + 1.0 = 5.5 > 5.0
    }

    public function testThrowsWhenExactlyAtLimit(): void
    {
        // 5.0 + 0.0001 > 5.0 → bloqué
        $limit = $this->buildLimit(amount: 5.0, consumption: 5.0);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $checker = $this->buildChecker();

        $this->expectException(LlmQuotaException::class);
        $checker->assertCanSpend('user-1', null, 0.0001);
    }

    // -------------------------------------------------------------------------
    // Dispatch de l'event
    // -------------------------------------------------------------------------

    public function testDispatchesEventBeforeThrowingException(): void
    {
        $limit = $this->buildLimit(amount: 5.0, consumption: 4.5);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(SynapseSpendingLimitExceededEvent::class));

        $checker = $this->buildChecker(dispatcher: $dispatcher);

        $this->expectException(LlmQuotaException::class);
        $checker->assertCanSpend('user-1', null, 1.0);
    }

    public function testEventCarriesCorrectData(): void
    {
        $limit = $this->buildLimit(amount: 10.0, consumption: 8.0, currency: 'USD');
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $capturedEvent = null;
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$capturedEvent) {
            $capturedEvent = $event;

            return $event;
        });

        $checker = $this->buildChecker(dispatcher: $dispatcher);

        try {
            $checker->assertCanSpend('user-42', null, 3.0);
        } catch (LlmQuotaException) {
            // attendue
        }

        $this->assertInstanceOf(SynapseSpendingLimitExceededEvent::class, $capturedEvent);
        $this->assertSame('user-42', $capturedEvent->getUserId());
        $this->assertSame('user', $capturedEvent->getScope());
        $this->assertSame(10.0, $capturedEvent->getLimitAmount());
        $this->assertSame(8.0, $capturedEvent->getConsumption());
        $this->assertSame(3.0, $capturedEvent->getEstimatedCost());
        $this->assertSame('USD', $capturedEvent->getCurrency());
        $this->assertEqualsWithDelta(11.0, $capturedEvent->getProjectedConsumption(), 0.0001);
        $this->assertEqualsWithDelta(1.0, $capturedEvent->getOverrunAmount(), 0.0001);
    }

    public function testNoEventDispatchedWhenUnderLimit(): void
    {
        $limit = $this->buildLimit(amount: 10.0, consumption: 5.0);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $checker = $this->buildChecker(dispatcher: $dispatcher);
        $checker->assertCanSpend('user-1', null, 2.0);
    }

    public function testNoEventDispatchedWithoutDispatcher(): void
    {
        $limit = $this->buildLimit(amount: 5.0, consumption: 4.5);
        $this->spendingLimitRepo->method('findForUser')->willReturn([$limit]);

        // Sans dispatcher injecté — ne doit pas lever d'erreur
        $checker = $this->buildChecker(dispatcher: null);

        $this->expectException(LlmQuotaException::class);
        $checker->assertCanSpend('user-1', null, 1.0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildChecker(?EventDispatcherInterface $dispatcher = null): SpendingLimitChecker
    {
        return new SpendingLimitChecker(
            tokenUsageRepo: $this->tokenUsageRepo,
            spendingLimitRepo: $this->spendingLimitRepo,
            configRepo: $this->configRepo,
            slidingDayHours: 4,
            timezone: new \DateTimeZone('UTC'),
            cache: null,
            eventDispatcher: $dispatcher,
        );
    }

    private function buildLimit(
        float $amount,
        float $consumption,
        string $currency = 'EUR',
        SpendingLimitPeriod $period = SpendingLimitPeriod::SLIDING_DAY,
    ): SynapseSpendingLimit {
        $limit = new SynapseSpendingLimit();
        $limit->setScope(SpendingLimitScope::USER);
        $limit->setScopeId('user-1');
        $limit->setAmount((string) $amount);
        $limit->setCurrency($currency);
        $limit->setPeriod($period);

        // tokenUsageRepo est un stub — willReturn est OK sans expects()
        $this->tokenUsageRepo
            ->method('getConsumptionForWindow')
            ->willReturn($consumption);

        return $limit;
    }
}
