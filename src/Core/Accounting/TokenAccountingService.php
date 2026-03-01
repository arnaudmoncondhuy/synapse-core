<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Accounting;

use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseUsageRecordedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service de tracking centralisé des tokens IA
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

    public function __construct(
        private \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository $modelRepo,
        private EntityManagerInterface $em,
        private string $referenceCurrency = 'EUR',
        private array $currencyRates = [],
        private ?CacheInterface $cache = null,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?ModelCapabilityRegistry $capabilityRegistry = null,
    ) {}

    /**
     * Log l'usage de tokens pour une action IA
     *
     * @param string $module Module concerné (ex: 'gmail', 'calendar', 'summarization')
     * @param string $action Action spécifique (ex: 'email_draft', 'event_suggestion')
     * @param string $model Modèle IA utilisé (ex: 'gemini-2.5-flash')
     * @param array $usage Usage détaillé ['prompt' => int, 'completion' => int, 'thinking' => int]
     * @param string|int|null $userId ID de l'utilisateur concerné (nullable pour tâches système)
     * @param string|null $conversationId ID de la conversation concernée (si applicable)
     * @param int|null $presetId ID du preset LLM utilisé (si applicable, pour plafonds par preset)
     * @param int|null $missionId ID de la mission (assistant) utilisée (si applicable, pour plafonds par mission)
     * @param array|null $metadata Métadonnées additionnelles (durée, debug_id, etc.)
     * @return SynapseLlmCall L'entité créée — permet au caller de récupérer le callId pour le lier à un SynapseMessage
     */
    public function logUsage(
        string $module,
        string $action,
        string $model,
        array $usage,
        string|int|null $userId = null,
        ?string $conversationId = null,
        ?int $presetId = null,
        ?int $missionId = null,
        ?array $metadata = null
    ): SynapseLlmCall {
        $tokenUsage = new SynapseLlmCall();
        $tokenUsage->setModule($module);
        $tokenUsage->setAction($action);
        $tokenUsage->setModel($model);

        // Récupérer le tarif actuel pour ce modèle (input, output, currency)
        // Hiérarchie: synapse_model (BDD) surcharge ModelCapabilityRegistry (YAML)
        $modelPricing = $this->getPricingForModel($model);

        // Tokens (format normalisé Synapse)
        $promptTokens     = $usage['prompt_tokens']     ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $thinkingTokens   = $usage['thinking_tokens']   ?? 0;

        $tokenUsage->setPromptTokens($promptTokens);
        $tokenUsage->setCompletionTokens($completionTokens);
        $tokenUsage->setThinkingTokens($thinkingTokens);
        $tokenUsage->calculateTotalTokens();

        // User ID (convertir en string pour uniformiser)
        if ($userId !== null) {
            $tokenUsage->setUserId((string) $userId);
        }

        // SynapseConversation ID
        if ($conversationId !== null) {
            $tokenUsage->setConversationId($conversationId);
        }

        // Preset LLM (pour plafonds par preset)
        if ($presetId !== null) {
            $tokenUsage->setPresetId($presetId);
        }

        // Mission (pour plafonds par mission)
        if ($missionId !== null) {
            $tokenUsage->setMissionId($missionId);
        }

        // Calculer et stocker le coût en colonnes dédiées (snapshot immuable au tarif du moment)
        $currentUsage = [
            'prompt_tokens'     => $promptTokens,
            'completion_tokens' => $completionTokens,
            'thinking_tokens'   => $thinkingTokens,
        ];
        $costInModelCurrency = $this->calculateCost($currentUsage, $modelPricing);
        $currency = $modelPricing['currency'] ?? 'USD';
        $costRef = $this->convertToReferenceCurrency($costInModelCurrency, $currency);

        $tokenUsage->setCostModelCurrency($costInModelCurrency);
        $tokenUsage->setCostReference($costRef);
        $tokenUsage->setPricingInput($modelPricing['input'] ?? null);
        $tokenUsage->setPricingOutput($modelPricing['output'] ?? null);
        $tokenUsage->setPricingCurrency($currency);

        // Métadonnées libres (sans coût — géré par les colonnes dédiées)
        $tokenUsage->setMetadata($metadata ?: null);

        $this->em->persist($tokenUsage);
        $this->em->flush();

        if ($this->cache !== null && $costRef > 0) {
            $this->incrementSpendingCache($userId !== null ? (string) $userId : null, $presetId, (float) $costRef);
        }

        if ($this->dispatcher !== null) {
            $this->dispatcher->dispatch(new SynapseUsageRecordedEvent(
                $module,
                $action,
                $model,
                $promptTokens,
                $completionTokens,
                $thinkingTokens,
                $costRef,
                $userId !== null ? (string) $userId : null,
                $conversationId,
                $presetId,
            ));
        }

        return $tokenUsage;
    }

    /**
     * Incrémente les compteurs cache pour les plafonds (sliding + calendar).
     */
    public function incrementSpendingCache(?string $userId, ?int $presetId, float $amountInReference): void
    {
        if ($this->cache === null || $amountInReference <= 0) {
            return;
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $keys = $this->getSpendingCacheKeysForRecord($userId, $presetId, $now);
        foreach ($keys as $key => $ttlSeconds) {
            $item = $this->cache->getItem($key);
            $current = $item->isHit() ? (float) $item->get() : 0.0;
            $item->set(round($current + $amountInReference, 6));
            $item->expiresAfter($ttlSeconds);
            $this->cache->save($item);
        }
    }

    /**
     * @return array<string, int> key => TTL seconds
     */
    private function getSpendingCacheKeysForRecord(?string $userId, ?int $presetId, \DateTimeImmutable $at): array
    {
        $keys = [];
        $date = $at->format('Y-m-d');
        $month = $at->format('Y-m');
        if ($userId !== null) {
            $keys[self::CACHE_PREFIX . 'user_' . $userId . '_sliding_day'] = 90000;   // 25h
            $keys[self::CACHE_PREFIX . 'user_' . $userId . '_sliding_month'] = 2678400; // 31d
            $keys[self::CACHE_PREFIX . 'user_' . $userId . '_calendar_day_' . $date] = 172800;   // 2d
            $keys[self::CACHE_PREFIX . 'user_' . $userId . '_calendar_month_' . $month] = 5184000; // 60d
        }
        if ($presetId !== null) {
            $keys[self::CACHE_PREFIX . 'preset_' . $presetId . '_sliding_day'] = 90000;
            $keys[self::CACHE_PREFIX . 'preset_' . $presetId . '_sliding_month'] = 2678400;
            $keys[self::CACHE_PREFIX . 'preset_' . $presetId . '_calendar_day_' . $date] = 172800;
            $keys[self::CACHE_PREFIX . 'preset_' . $presetId . '_calendar_month_' . $month] = 5184000;
        }
        return $keys;
    }

    /**
     * Calcule le coût estimé d'un usage dans la devise du modèle.
     *
     * @param array $usage   Usage détaillé ['prompt_tokens' => int, 'completion_tokens' => int, 'thinking_tokens' => int]
     * @param array $pricing Tarifs ['input' => float, 'output' => float, 'currency' => string]
     * @return float Coût dans la devise du modèle
     */
    public function calculateCost(array $usage, array $pricing): float
    {
        $promptTokens     = $usage['prompt_tokens']     ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $thinkingTokens   = $usage['thinking_tokens']   ?? 0;

        $inputCost = ($promptTokens / 1_000_000) * ($pricing['input'] ?? 0);
        $outputCost = (($completionTokens + $thinkingTokens) / 1_000_000) * ($pricing['output'] ?? 0);

        return round($inputCost + $outputCost, 6);
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
        if ($rate === null) {
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
        if ($this->capabilityRegistry !== null) {
            try {
                $capabilities = $this->capabilityRegistry->getCapabilities($model);
                if ($capabilities->pricingInput !== null || $capabilities->pricingOutput !== null) {
                    // Déduire la devise basée sur le provider
                    $currency = match($capabilities->provider) {
                        'ovh' => 'EUR',
                        'gemini' => 'USD',
                        default => 'USD',
                    };

                    return [
                        'input' => $capabilities->pricingInput ?? 0.0,
                        'output' => $capabilities->pricingOutput ?? 0.0,
                        'currency' => $currency,
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
