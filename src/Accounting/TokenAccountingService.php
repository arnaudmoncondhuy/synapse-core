<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Accounting;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseUsageRecordedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service de tracking centralisé des tokens IA.
 *
 * Permet de logger la consommation de tokens pour toutes les fonctionnalités
 * IA de l'application (pas seulement les conversations).
 *
 * Les conversations (chat) sont trackées via SynapseMessage.tokens,
 * ce service est pour les tâches automatisées et agrégations.
 */
class TokenAccountingService
{
    private const CACHE_PREFIX = 'synapse_spending_';

    /**
     * @param array<string, float> $currencyRates
     */
    public function __construct(
        private readonly \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository $modelRepo,
        private readonly EntityManagerInterface $em,
        #[Autowire('%synapse.token_tracking.reference_currency%')]
        private readonly string $referenceCurrency = 'EUR',
        #[Autowire('%synapse.token_tracking.currency_rates%')]
        private readonly array $currencyRates = [],
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?ModelCapabilityRegistry $capabilityRegistry = null,
    ) {
    }

    /**
     * Log l'usage de tokens pour une action IA.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function logUsage(
        string $module,
        string $action,
        string $model,
        TokenUsage $usage,
        string|int|null $userId = null,
        ?string $conversationId = null,
        ?int $presetId = null,
        ?int $agentId = null,
        ?array $metadata = null,
    ): SynapseLlmCall {
        $llmCall = new SynapseLlmCall();
        $llmCall->setModule($module);
        $llmCall->setAction($action);
        $llmCall->setModel($model);

        // Récupérer le tarif actuel pour ce modèle (input, output, currency)
        $modelPricing = $this->getPricingForModel($model);

        $llmCall->setPromptTokens($usage->promptTokens);
        $llmCall->setCompletionTokens($usage->completionTokens);
        $llmCall->setThinkingTokens($usage->thinkingTokens);
        $llmCall->calculateTotalTokens();

        if (null !== $userId) {
            $llmCall->setUserId((string) $userId);
        }

        if (null !== $conversationId) {
            $llmCall->setConversationId($conversationId);
        }

        if (null !== $presetId) {
            $llmCall->setPresetId($presetId);
        }

        if (null !== $agentId) {
            $llmCall->setAgentId($agentId);
        }

        $costInModelCurrency = $this->calculateCostFromVO($usage, $modelPricing);
        $currency = $modelPricing['currency'] ?? 'USD';
        $costRef = $this->convertToReferenceCurrency($costInModelCurrency, $currency);

        $llmCall->setCostModelCurrency($costInModelCurrency);
        $llmCall->setCostReference($costRef);
        $llmCall->setPricingInput($modelPricing['input'] ?? null);
        $llmCall->setPricingOutput($modelPricing['output'] ?? null);
        $llmCall->setPricingCurrency($currency);

        $llmCall->setMetadata($metadata ?: null);

        $this->em->persist($llmCall);
        $this->em->flush();

        if (null !== $this->cache && $costRef > 0) {
            $this->incrementSpendingCache(null !== $userId ? (string) $userId : null, $presetId, (float) $costRef, $agentId);
        }

        if (null !== $this->dispatcher) {
            $this->dispatcher->dispatch(new SynapseUsageRecordedEvent(
                $module,
                $action,
                $model,
                $usage->promptTokens,
                $usage->completionTokens,
                $usage->thinkingTokens,
                $costRef,
                null !== $userId ? (string) $userId : null,
                $conversationId,
                $presetId,
                $agentId,
            ));
        }

        return $llmCall;
    }

    /**
     * Incrémente les compteurs cache pour les plafonds (sliding + calendar).
     */
    public function incrementSpendingCache(?string $userId, ?int $presetId, float $amountInReference, ?int $agentId = null): void
    {
        if (null === $this->cache || $amountInReference <= 0) {
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $keys = $this->getSpendingCacheKeysForRecord($userId, $presetId, $now, $agentId);
        foreach ($keys as $key => $ttlSeconds) {
            $item = $this->cache->getItem($key);
            $val = $item->get();
            $current = $item->isHit() && is_numeric($val) ? (float) $val : 0.0;
            $item->set(round($current + $amountInReference, 6));
            $item->expiresAfter($ttlSeconds);
            $this->cache->save($item);
        }
    }

    /**
     * @return array<string, int> key => TTL seconds
     */
    private function getSpendingCacheKeysForRecord(?string $userId, ?int $presetId, \DateTimeImmutable $at, ?int $agentId = null): array
    {
        $keys = [];
        $date = $at->format('Y-m-d');
        $month = $at->format('Y-m');
        if (null !== $userId) {
            $keys[self::CACHE_PREFIX.'user_'.$userId.'_sliding_day'] = 90000;   // 25h
            $keys[self::CACHE_PREFIX.'user_'.$userId.'_sliding_month'] = 2678400; // 31d
            $keys[self::CACHE_PREFIX.'user_'.$userId.'_calendar_day_'.$date] = 172800;   // 2d
            $keys[self::CACHE_PREFIX.'user_'.$userId.'_calendar_month_'.$month] = 5184000; // 60d
        }
        if (null !== $presetId) {
            $keys[self::CACHE_PREFIX.'preset_'.$presetId.'_sliding_day'] = 90000;
            $keys[self::CACHE_PREFIX.'preset_'.$presetId.'_sliding_month'] = 2678400;
            $keys[self::CACHE_PREFIX.'preset_'.$presetId.'_calendar_day_'.$date] = 172800;
            $keys[self::CACHE_PREFIX.'preset_'.$presetId.'_calendar_month_'.$month] = 5184000;
        }
        if (null !== $agentId) {
            $keys[self::CACHE_PREFIX.'agent_'.$agentId.'_sliding_day'] = 90000;
            $keys[self::CACHE_PREFIX.'agent_'.$agentId.'_sliding_month'] = 2678400;
            $keys[self::CACHE_PREFIX.'agent_'.$agentId.'_calendar_day_'.$date] = 172800;
            $keys[self::CACHE_PREFIX.'agent_'.$agentId.'_calendar_month_'.$month] = 5184000;
        }

        return $keys;
    }

    /**
     * Calcule le coût estimé d'un usage dans la devise du modèle.
     *
     * @param array{input: float, output: float, currency: string} $pricing
     */
    public function calculateCostFromVO(TokenUsage $usage, array $pricing): float
    {
        $inputCost = ($usage->promptTokens / 1_000_000) * $pricing['input'];
        $outputCost = (($usage->completionTokens + $usage->thinkingTokens) / 1_000_000) * $pricing['output'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * @deprecated Utiliser calculateCostFromVO(TokenUsage, pricing)
     *
     * @param array<string, int> $usage
     * @param array{input: float, output: float, currency: string} $pricing
     */
    public function calculateCost(array $usage, array $pricing): float
    {
        return $this->calculateCostFromVO(TokenUsage::fromArray($usage), $pricing);
    }

    /**
     * Convertit un montant vers la devise de référence (pour plafonds et agrégats).
     */
    public function convertToReferenceCurrency(float $amount, string $fromCurrency): float
    {
        if ($fromCurrency === $this->referenceCurrency) {
            return $amount;
        }
        $rate = $this->currencyRates[$fromCurrency] ?? null;
        if (null === $rate || !is_numeric($rate)) {
            return $amount; // Pas de taux = pas de conversion (audit en devise d'origine)
        }

        return round($amount * (float) $rate, 6);
    }

    public function getReferenceCurrency(): string
    {
        return $this->referenceCurrency;
    }

    /**
     * Récupère les tarifs pour un modèle avec fallback hiérarchique.
     *
     * Hiérarchie:
     * 1. synapse_model (BDD override) — toute info manquante est complétée par YAML/provider
     * 2. ModelCapabilityRegistry (YAML config) — source de tarifs par défaut
     * 3. Defaults (0.0 USD)
     *
     * @return array{input: float, output: float, currency: string}
     */
    private function getPricingForModel(string $model): array
    {
        // 1. Chercher dans synapse_model (BDD)
        $pricingMap = $this->modelRepo->findAllPricingMap();
        if (isset($pricingMap[$model])) {
            return $pricingMap[$model];
        }

        // 2. Fallback sur ModelCapabilityRegistry (YAML)
        if (null !== $this->capabilityRegistry) {
            try {
                $capabilities = $this->capabilityRegistry->getCapabilities($model);
                if (null !== $capabilities->pricingInput || null !== $capabilities->pricingOutput) {
                    return [
                        'input' => $capabilities->pricingInput ?? 0.0,
                        'output' => $capabilities->pricingOutput ?? 0.0,
                        'currency' => $capabilities->currency,
                    ];
                }
            } catch (\Throwable $e) {
                // Silently fallthrough to defaults
            }
        }

        // 3. Defaults
        return ['input' => 0.0, 'output' => 0.0, 'currency' => 'USD'];
    }
}
