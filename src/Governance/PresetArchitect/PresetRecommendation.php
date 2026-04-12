<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;

/**
 * Recommandation immutable produite par le PresetArchitect.
 *
 * Contient toutes les informations nécessaires pour créer un {@see SynapseModelPreset},
 * plus une justification en langage naturel et l'indication du mode de génération.
 */
final readonly class PresetRecommendation
{
    /**
     * @param ?array<string, mixed> $providerOptions
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $suggestedName,
        public string $suggestedKey,
        public float $temperature,
        public float $topP,
        public ?int $topK,
        public ?int $maxOutputTokens,
        public bool $streamingEnabled,
        public ?array $providerOptions,
        public ModelRange $range,
        public ?string $rgpdRisk,
        public string $justification,
        public bool $llmAssisted,
    ) {
    }

    /**
     * Construit une entité SynapseModelPreset à partir de la recommandation.
     *
     * Le preset est créé INACTIF — l'appelant décide de l'activation.
     */
    public function toPresetEntity(): SynapseModelPreset
    {
        $preset = new SynapseModelPreset();
        $preset->setName($this->suggestedName);
        $preset->setKey($this->suggestedKey);
        $preset->setProviderName($this->provider);
        $preset->setModel($this->model);
        $preset->setGenerationTemperature($this->temperature);
        $preset->setGenerationTopP($this->topP);
        $preset->setGenerationTopK($this->topK);
        $preset->setGenerationMaxOutputTokens($this->maxOutputTokens);
        $preset->setStreamingEnabled($this->streamingEnabled);
        $preset->setProviderOptions($this->providerOptions);
        $preset->setIsActive(false);

        return $preset;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'model' => $this->model,
            'suggested_name' => $this->suggestedName,
            'suggested_key' => $this->suggestedKey,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'max_output_tokens' => $this->maxOutputTokens,
            'streaming_enabled' => $this->streamingEnabled,
            'provider_options' => $this->providerOptions,
            'range' => $this->range->value,
            'rgpd_risk' => $this->rgpdRisk,
            'justification' => $this->justification,
            'llm_assisted' => $this->llmAssisted,
        ];
    }
}
