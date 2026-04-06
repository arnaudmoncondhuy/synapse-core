<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use PHPUnit\Framework\TestCase;

class SynapseSpendingLimitTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $limit = new SynapseSpendingLimit();

        $this->assertNull($limit->getId());
        $this->assertSame('', $limit->getScopeId());
        $this->assertSame('0.000000', $limit->getAmount());
        $this->assertSame('EUR', $limit->getCurrency());
        $this->assertNull($limit->getName());
        $this->assertInstanceOf(\DateTimeImmutable::class, $limit->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $limit->getUpdatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $limit = new SynapseSpendingLimit();

        $limit->setScope(SpendingLimitScope::USER)
            ->setScopeId('user-42')
            ->setAmount('10.500000')
            ->setCurrency('USD')
            ->setPeriod(SpendingLimitPeriod::CALENDAR_MONTH)
            ->setName('Monthly user limit');

        $this->assertSame(SpendingLimitScope::USER, $limit->getScope());
        $this->assertSame('user-42', $limit->getScopeId());
        $this->assertSame('10.500000', $limit->getAmount());
        $this->assertSame('USD', $limit->getCurrency());
        $this->assertSame(SpendingLimitPeriod::CALENDAR_MONTH, $limit->getPeriod());
        $this->assertSame('Monthly user limit', $limit->getName());
    }

    public function testAllScopesAndPeriods(): void
    {
        $limit = new SynapseSpendingLimit();

        $limit->setScope(SpendingLimitScope::PRESET)->setPeriod(SpendingLimitPeriod::SLIDING_DAY);
        $this->assertSame(SpendingLimitScope::PRESET, $limit->getScope());
        $this->assertSame(SpendingLimitPeriod::SLIDING_DAY, $limit->getPeriod());

        $limit->setScope(SpendingLimitScope::AGENT)->setPeriod(SpendingLimitPeriod::SLIDING_MONTH);
        $this->assertSame(SpendingLimitScope::AGENT, $limit->getScope());
        $this->assertSame(SpendingLimitPeriod::SLIDING_MONTH, $limit->getPeriod());
    }
}
