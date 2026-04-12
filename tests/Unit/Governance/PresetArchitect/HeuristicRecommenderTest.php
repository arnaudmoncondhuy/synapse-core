<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\PresetArchitect;

use ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\HeuristicRecommender;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class HeuristicRecommenderTest extends TestCase
{
    private HeuristicRecommender $recommender;

    protected function setUp(): void
    {
        $this->recommender = new HeuristicRecommender();
    }

    public function testPrefersBalancedOverFlagship(): void
    {
        $candidates = [
            $this->buildCandidate('opus', 'anthropic', ModelRange::FLAGSHIP, 15.0, 75.0),
            $this->buildCandidate('sonnet', 'anthropic', ModelRange::BALANCED, 3.0, 15.0),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertSame('sonnet', $recommendation->model);
        $this->assertSame(ModelRange::BALANCED, $recommendation->range);
    }

    public function testPrefersBalancedOverFast(): void
    {
        $candidates = [
            $this->buildCandidate('haiku', 'anthropic', ModelRange::FAST, 1.0, 5.0),
            $this->buildCandidate('sonnet', 'anthropic', ModelRange::BALANCED, 3.0, 15.0),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertSame('sonnet', $recommendation->model);
    }

    public function testCheapestBalancedWinsWhenMultiple(): void
    {
        $candidates = [
            $this->buildCandidate('expensive', 'provider_a', ModelRange::BALANCED, 10.0, 50.0),
            $this->buildCandidate('cheap', 'provider_b', ModelRange::BALANCED, 0.30, 2.50),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertSame('cheap', $recommendation->model);
    }

    public function testFallsBackToFlagshipWhenNoBalanced(): void
    {
        $candidates = [
            $this->buildCandidate('fast-model', 'provider', ModelRange::FAST, 1.0, 5.0),
            $this->buildCandidate('flagship-model', 'provider', ModelRange::FLAGSHIP, 15.0, 75.0),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertSame('flagship-model', $recommendation->model);
    }

    public function testTemperatureMatchesRange(): void
    {
        $candidates = [
            $this->buildCandidate('model', 'provider', ModelRange::FLAGSHIP, 15.0, 75.0),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertSame(0.7, $recommendation->temperature);
    }

    public function testThinkingBudgetWhenSupported(): void
    {
        $caps = new ModelCapabilities(
            model: 'model',
            provider: 'provider',
            range: ModelRange::BALANCED,
            supportsThinking: true,
            supportsTextGeneration: true,
            maxOutputTokens: 64000,
            pricingInput: 3.0,
            pricingOutput: 15.0,
        );
        $candidates = [
            ['modelId' => 'model', 'provider' => 'provider', 'providerLabel' => 'Provider', 'capabilities' => $caps],
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertNotNull($recommendation->providerOptions);
        $this->assertSame(16000, $recommendation->providerOptions['thinking']['budget_tokens']);
    }

    public function testNoThinkingWhenNotSupported(): void
    {
        $candidates = [
            $this->buildCandidate('model', 'provider', ModelRange::BALANCED, 3.0, 15.0, supportsThinking: false),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertNull($recommendation->providerOptions);
    }

    public function testTopKNullWhenNotSupported(): void
    {
        $candidates = [
            $this->buildCandidate('model', 'provider', ModelRange::BALANCED, 3.0, 15.0, supportsTopK: false),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertNull($recommendation->topK);
    }

    public function testTopKSetWhenSupported(): void
    {
        $candidates = [
            $this->buildCandidate('model', 'provider', ModelRange::BALANCED, 3.0, 15.0, supportsTopK: true),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertSame(40, $recommendation->topK);
    }

    public function testLlmAssistedIsFalse(): void
    {
        $candidates = [
            $this->buildCandidate('model', 'provider', ModelRange::BALANCED, 3.0, 15.0),
        ];

        $recommendation = $this->recommender->recommend($candidates);

        $this->assertFalse($recommendation->llmAssisted);
    }

    public function testThrowsOnEmptyCandidates(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->recommender->recommend([]);
    }

    /**
     * @return array{modelId: string, provider: string, providerLabel: string, capabilities: ModelCapabilities}
     */
    private function buildCandidate(
        string $modelId,
        string $provider,
        ModelRange $range,
        float $pricingInput,
        float $pricingOutput,
        bool $supportsThinking = false,
        bool $supportsTopK = false,
    ): array {
        $caps = new ModelCapabilities(
            model: $modelId,
            provider: $provider,
            range: $range,
            supportsThinking: $supportsThinking,
            supportsTopK: $supportsTopK,
            supportsTextGeneration: true,
            supportsStreaming: true,
            pricingInput: $pricingInput,
            pricingOutput: $pricingOutput,
            maxOutputTokens: 64000,
        );

        return [
            'modelId' => $modelId,
            'provider' => $provider,
            'providerLabel' => ucfirst($provider),
            'capabilities' => $caps,
        ];
    }
}
