<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Accounting;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ContextTruncationService;

/**
 * Estime le coût d'une requête LLM avant envoi (prompt + output max).
 */
class TokenCostEstimator
{
    private const DEFAULT_MAX_OUTPUT_TOKENS = 2048;

    public function __construct(
        private ConfigProviderInterface $configProvider,
        private \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository $modelRepo,
        private ContextTruncationService $contextTruncationService,
        private TokenAccountingService $accountingService,
    ) {}

    /**
     * Estime le coût d'une requête à partir du contenu (historique + nouveau message).
     *
     * @param array<int, array{role: string, content?: string|null}> $contents   Historique au format OpenAI (optionnel) + nouveau message
     * @param string|null                                           $model      Modèle (null = modèle actif depuis la config)
     * @param int|null                                              $maxOutput  Max tokens en sortie (null = config ou défaut 2048)
     * @return array{prompt_tokens: int, estimated_output_tokens: int, cost_model_currency: float, cost_reference: float, currency: string}
     */
    public function estimateCost(array $contents, ?string $model = null, ?int $maxOutput = null): array
    {
        $config = $this->configProvider->getConfig();
        $effectiveModel = $model ?? $config['model'] ?? 'unknown';
        $maxOutputTokens = $maxOutput ?? $config['generation_config']['max_output_tokens'] ?? self::DEFAULT_MAX_OUTPUT_TOKENS;

        $promptTokens = $this->contextTruncationService->estimateTokensForContents($contents);

        $pricingMap = $this->modelRepo->findAllPricingMap();
        $pricing = $pricingMap[$effectiveModel] ?? ['input' => 0.0, 'output' => 0.0, 'currency' => 'USD'];

        $usage = [
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $maxOutputTokens,
            'thinking_tokens' => 0,
        ];
        $costModelCurrency = $this->accountingService->calculateCost($usage, $pricing);
        $currency = $pricing['currency'] ?? 'USD';
        $costReference = $this->accountingService->convertToReferenceCurrency($costModelCurrency, $currency);

        return [
            'prompt_tokens' => $promptTokens,
            'estimated_output_tokens' => $maxOutputTokens,
            'cost_model_currency' => round($costModelCurrency, 6),
            'cost_reference' => round($costReference, 6),
            'currency' => $currency,
        ];
    }
}
