<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Accounting;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ContextTruncationService;

/**
 * Estime le coût d'une requête LLM avant envoi (prompt + output max).
 */
class TokenCostEstimator
{
    private const DEFAULT_MAX_OUTPUT_TOKENS = 2048;

    public function __construct(
        private readonly ConfigProviderInterface $configProvider,
        private readonly \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository $modelRepo,
        private readonly ContextTruncationService $contextTruncationService,
        private readonly TokenAccountingService $accountingService,
    ) {
    }

    /**
     * Estime le coût d'une requête à partir du contenu (historique + nouveau message).
     *
     * @param array<int, array{role: string, content?: string|null}> $contents Historique au format OpenAI (optionnel) + nouveau message
     * @param string|null $model Modèle (null = modèle actif depuis la config)
     * @param int|null $maxOutput Max tokens en sortie (null = config ou défaut 2048)
     *
     * @return array{prompt_tokens: int, estimated_output_tokens: int, cost_model_currency: float, cost_reference: float, currency: string}
     */
    public function estimateCost(array $contents, ?string $model = null, ?int $maxOutput = null): array
    {
        $config = $this->configProvider->getConfig();
        $effectiveModel = $model ?? ($config->model ?: 'unknown');
        $maxOutputTokens = $maxOutput ?? ($config->generation->maxOutputTokens ?? self::DEFAULT_MAX_OUTPUT_TOKENS);

        $promptTokens = $this->contextTruncationService->estimateTokensForContents($contents);

        $pricingMap = $this->modelRepo->findAllPricingMap();
        /** @var array{input: float, output: float, currency: string} $pricing */
        $pricing = isset($pricingMap[$effectiveModel]) && is_array($pricingMap[$effectiveModel])
            ? [
                'input' => (float) ($pricingMap[$effectiveModel]['input'] ?? 0.0),
                'output' => (float) ($pricingMap[$effectiveModel]['output'] ?? 0.0),
                'currency' => is_string($pricingMap[$effectiveModel]['currency'] ?? null) ? (string) $pricingMap[$effectiveModel]['currency'] : 'USD',
            ]
            : ['input' => 0.0, 'output' => 0.0, 'currency' => 'USD'];

        $usage = new \ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage($promptTokens, $maxOutputTokens);
        $costModelCurrency = $this->accountingService->calculateCostFromVO($usage, $pricing);
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
