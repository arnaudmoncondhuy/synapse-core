<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\SpendingWarmCacheCommand;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class SpendingWarmCacheCommandTest extends TestCase
{
    public function testNoCacheConfiguredShowsWarning(): void
    {
        $spendingLimitRepo = $this->createStub(SynapseSpendingLimitRepository::class);
        $tokenUsageRepo = $this->createStub(SynapseLlmCallRepository::class);

        $command = new SpendingWarmCacheCommand($spendingLimitRepo, $tokenUsageRepo, null);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aucun cache', $tester->getDisplay());
    }

    public function testWarmCacheWithNoLimits(): void
    {
        $spendingLimitRepo = $this->createStub(SynapseSpendingLimitRepository::class);
        $spendingLimitRepo->method('findAll')->willReturn([]);

        $tokenUsageRepo = $this->createStub(SynapseLlmCallRepository::class);
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects($this->never())->method('save');

        $command = new SpendingWarmCacheCommand($spendingLimitRepo, $tokenUsageRepo, $cache);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('0 plafond(s)', $tester->getDisplay());
    }

    public function testWarmCacheWithOneLimitUpdatesCache(): void
    {
        $limit = $this->createStub(SynapseSpendingLimit::class);
        $limit->method('getScope')->willReturn(SpendingLimitScope::USER);
        $limit->method('getScopeId')->willReturn('user-1');
        $limit->method('getPeriod')->willReturn(SpendingLimitPeriod::SLIDING_DAY);

        $spendingLimitRepo = $this->createStub(SynapseSpendingLimitRepository::class);
        $spendingLimitRepo->method('findAll')->willReturn([$limit]);

        $tokenUsageRepo = $this->createStub(SynapseLlmCallRepository::class);
        $tokenUsageRepo->method('getConsumptionForWindow')->willReturn(123.45);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem->expects($this->once())->method('set')->with(123.45);
        $cacheItem->expects($this->once())->method('expiresAfter')->with(90000);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);
        $cache->expects($this->once())->method('save')->with($cacheItem);

        $command = new SpendingWarmCacheCommand($spendingLimitRepo, $tokenUsageRepo, $cache);
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('1 plafond(s)', $tester->getDisplay());
    }
}
