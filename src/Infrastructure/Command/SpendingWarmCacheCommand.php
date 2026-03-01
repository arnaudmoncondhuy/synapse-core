<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Command;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recalcule les compteurs de consommation (plafonds) depuis la DB et repopule le cache.
 *
 * Utile après une migration, une correction manuelle ou si le cache a été vidé.
 */
#[AsCommand(
    name: 'synapse:spending:warm-cache',
    description: 'Recalcule les compteurs de dépense depuis la DB et met à jour le cache',
)]
final class SpendingWarmCacheCommand extends Command
{
    public function __construct(
        private SynapseSpendingLimitRepository $spendingLimitRepo,
        private SynapseLlmCallRepository $tokenUsageRepo,
        private ?CacheInterface $cache = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->cache === null) {
            $io->warning('Aucun cache configuré pour Synapse spending. Rien à réchauffer.');
            return Command::SUCCESS;
        }

        $limits = $this->spendingLimitRepo->findAll();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $updated = 0;

        foreach ($limits as $limit) {
            $scope = $limit->getScope();
            $scopeId = $limit->getScopeId();
            $period = $limit->getPeriod();

            [$start, $end] = $this->getWindow($period, $now);
            $consumption = $this->tokenUsageRepo->getConsumptionForWindow($scope->value, $scopeId, $start, $end);

            $key = $this->buildCacheKey($scope->value, $scopeId, $period, $start);
            $item = $this->cache->getItem($key);
            $item->set($consumption);
            $item->expiresAfter($period->value === 'sliding_day' ? 90000 : ($period->value === 'sliding_month' ? 2678400 : 3600));
            $this->cache->save($item);
            $updated++;
        }

        $io->success(sprintf('Cache mis à jour pour %d plafond(s).', $updated));
        return Command::SUCCESS;
    }

    private function getWindow(\ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod $period, \DateTimeImmutable $now): array
    {
        return match ($period) {
            \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod::SLIDING_DAY => [$now->modify('-24 hours'), $now],
            \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod::SLIDING_MONTH => [$now->modify('-30 days'), $now],
            \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod::CALENDAR_DAY => [$now->setTime(0, 0, 0), $now->setTime(23, 59, 59)],
            \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod::CALENDAR_MONTH => [
                $now->modify('first day of this month')->setTime(0, 0, 0),
                $now->modify('last day of this month')->setTime(23, 59, 59),
            ],
        };
    }

    private function buildCacheKey(string $scope, string $scopeId, \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod $period, \DateTimeInterface $start): string
    {
        $base = 'synapse:spending:' . $scope . ':' . $scopeId . ':' . $period->value;
        return match ($period) {
            \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod::CALENDAR_DAY => $base . ':' . $start->format('Y-m-d'),
            \ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod::CALENDAR_MONTH => $base . ':' . $start->format('Y-m'),
            default => $base,
        };
    }
}
