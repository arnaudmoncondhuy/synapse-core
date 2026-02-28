<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Accounting;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitPeriod;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\SpendingLimitScope;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimit;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseSpendingLimitRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseTokenUsageRepository;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Vérifie les plafonds de dépense avant une requête LLM.
 *
 * À appeler avant ChatService::ask avec le coût estimé.
 * Lève LlmQuotaException si un plafond (user ou preset) serait dépassé.
 * Utilise le cache pour la consommation quand disponible (fallback DB).
 */
class SpendingLimitChecker
{
    private const CACHE_PREFIX = 'synapse:spending:';

    public function __construct(
        private SynapseTokenUsageRepository $tokenUsageRepo,
        private SynapseSpendingLimitRepository $spendingLimitRepo,
        private SynapseConfigRepository $configRepo,
        private string $referenceCurrency = 'EUR',
        private ?\DateTimeZone $timezone = null,
        private ?CacheInterface $cache = null,
    ) {
        $this->timezone ??= new \DateTimeZone('UTC');
    }

    /**
     * Vérifie que l'utilisateur / le preset / la mission peut encore dépenser le montant estimé.
     *
     * @param string      $userId              ID utilisateur (string)
     * @param int|null    $presetId            ID du preset utilisé (optionnel)
     * @param float       $estimatedCostRef    Coût estimé en devise de référence
     * @param int|null    $missionId           ID de la mission utilisée (optionnel)
     * @throws LlmQuotaException Si un plafond serait dépassé
     */
    public function assertCanSpend(string $userId, ?int $presetId, float $estimatedCostRef, ?int $missionId = null): void
    {
        if (!$this->configRepo->getGlobalConfig()->isSpendingLimitsEnabled()) {
            return;
        }

        $limits = [];

        foreach ($this->spendingLimitRepo->findForUser($userId) as $limit) {
            $limits[] = $limit;
        }
        if ($presetId !== null) {
            foreach ($this->spendingLimitRepo->findForPreset($presetId) as $limit) {
                $limits[] = $limit;
            }
        }
        if ($missionId !== null) {
            foreach ($this->spendingLimitRepo->findForMission($missionId) as $limit) {
                $limits[] = $limit;
            }
        }

        foreach ($limits as $limit) {
            [$start, $end] = $this->getWindow($limit->getPeriod());
            $scopeId = $limit->getScopeId();
            $scope = $limit->getScope()->value;

            $consumption = $this->getConsumptionFromCacheOrDb($scope, $scopeId, $limit->getPeriod(), $start, $end);
            $limitAmount = (float) $limit->getAmount();

            if ($consumption + $estimatedCostRef > $limitAmount) {
                throw new LlmQuotaException(sprintf(
                    'Plafond de dépense atteint (%s / %s %s). Réessayez plus tard ou contactez l\'administrateur.',
                    number_format($consumption + $estimatedCostRef, 4),
                    number_format($limitAmount, 4),
                    $limit->getCurrency()
                ));
            }
        }
    }

    /**
     * Récupère la consommation (devise de référence) depuis le cache ou la DB.
     */
    private function getConsumptionFromCacheOrDb(string $scope, string $scopeId, SpendingLimitPeriod $period, \DateTimeInterface $start, \DateTimeInterface $end): float
    {
        $key = $this->buildCacheKey($scope, $scopeId, $period, $start);
        if ($this->cache !== null) {
            $item = $this->cache->getItem($key);
            if ($item->isHit()) {
                return (float) $item->get();
            }
        }

        $consumption = $this->tokenUsageRepo->getConsumptionForWindow($scope, $scopeId, $start, $end);

        if ($this->cache !== null) {
            $item = $this->cache->getItem($key);
            $item->set($consumption);
            $item->expiresAfter($period === SpendingLimitPeriod::SLIDING_DAY ? 90000 : ($period === SpendingLimitPeriod::SLIDING_MONTH ? 2678400 : 3600));
            $this->cache->save($item);
        }

        return $consumption;
    }

    private function buildCacheKey(string $scope, string $scopeId, SpendingLimitPeriod $period, \DateTimeInterface $start): string
    {
        $base = self::CACHE_PREFIX . $scope . ':' . $scopeId . ':' . $period->value;
        return match ($period) {
            SpendingLimitPeriod::CALENDAR_DAY => $base . ':' . $start->format('Y-m-d'),
            SpendingLimitPeriod::CALENDAR_MONTH => $base . ':' . $start->format('Y-m'),
            default => $base,
        };
    }

    /**
     * Retourne [début, fin] de la fenêtre pour la période donnée.
     *
     * @return array{\DateTimeInterface, \DateTimeInterface}
     */
    private function getWindow(SpendingLimitPeriod $period): array
    {
        $now = new \DateTimeImmutable('now', $this->timezone);

        return match ($period) {
            SpendingLimitPeriod::SLIDING_DAY => [
                $now->modify('-24 hours'),
                $now,
            ],
            SpendingLimitPeriod::SLIDING_MONTH => [
                $now->modify('-30 days'),
                $now,
            ],
            SpendingLimitPeriod::CALENDAR_DAY => [
                $now->setTime(0, 0, 0),
                $now->setTime(23, 59, 59),
            ],
            SpendingLimitPeriod::CALENDAR_MONTH => [
                $now->modify('first day of this month')->setTime(0, 0, 0),
                $now->modify('last day of this month')->setTime(23, 59, 59),
            ],
        };
    }
}
