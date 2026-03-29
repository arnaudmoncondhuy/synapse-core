<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object contenant la configuration runtime complète résolue pour un appel LLM.
 *
 * Remplace les array<string, mixed> $config passés partout dans le système.
 */
final class SynapseRuntimeConfig
{
    public function __construct(
        public readonly string $model,
        public readonly string $provider,
        public readonly ?int $presetId = null,
        public readonly ?string $presetName = null,
        public readonly GenerationConfig $generation = new GenerationConfig(),
        public readonly ThinkingConfig $thinking = new ThinkingConfig(),
        public readonly SafetySettings $safety = new SafetySettings(),
        /** @var array<string, mixed> */
        public readonly array $providerCredentials = [],
        public readonly bool $streamingEnabled = true,
        public readonly bool $debugMode = false,
        public readonly int $maxTurns = 5,
        /** @var list<string> */
        public readonly array $disabledCapabilities = [],
        public readonly ?string $systemPrompt = null,
        public readonly ?string $masterPrompt = null,
        public readonly bool $masterPromptStateless = true,
        public readonly ?string $activeTone = null,
        public readonly ?int $agentId = null,
        public readonly ?string $agentName = null,
        public readonly ?string $agentEmoji = null,
        public readonly ?float $pricingInput = null,
        public readonly ?float $pricingOutput = null,
        public readonly ?string $vertexRegion = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            model: (string) ($data['model'] ?? ''),
            provider: (string) ($data['provider'] ?? $data['provider_name'] ?? ''),
            presetId: isset($data['preset_id']) ? (int) $data['preset_id'] : null,
            presetName: $data['preset_name'] ?? null,
            generation: GenerationConfig::fromArray($data['generation_config'] ?? []),
            thinking: ThinkingConfig::fromArray($data['thinking'] ?? []),
            safety: SafetySettings::fromArray($data['safety_settings'] ?? []),
            providerCredentials: (array) ($data['provider_credentials'] ?? []),
            streamingEnabled: (bool) ($data['streaming_enabled'] ?? true),
            debugMode: (bool) ($data['debug_mode'] ?? false),
            maxTurns: (int) ($data['max_turns'] ?? 5),
            disabledCapabilities: array_values(array_map('strval', (array) ($data['disabled_capabilities'] ?? []))),
            systemPrompt: $data['system_prompt'] ?? null,
            masterPrompt: $data['master_prompt'] ?? null,
            masterPromptStateless: (bool) ($data['master_prompt_stateless'] ?? true),
            activeTone: $data['active_tone'] ?? null,
            agentId: isset($data['agent_id']) ? (int) $data['agent_id'] : null,
            agentName: $data['agent_name'] ?? null,
            agentEmoji: $data['agent_emoji'] ?? null,
            pricingInput: isset($data['pricing_input']) ? (float) $data['pricing_input'] : null,
            pricingOutput: isset($data['pricing_output']) ? (float) $data['pricing_output'] : null,
            vertexRegion: $data['vertex_region'] ?? null,
        );
    }

    /**
     * Rétrocompatibilité temporaire — à supprimer quand tout le code utilise les propriétés typées.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'provider' => $this->provider,
            'provider_name' => $this->provider,
            'preset_id' => $this->presetId,
            'preset_name' => $this->presetName,
            'generation_config' => [
                'temperature' => $this->generation->temperature,
                'top_p' => $this->generation->topP,
                'top_k' => $this->generation->topK,
                'max_output_tokens' => $this->generation->maxOutputTokens,
                'stop_sequences' => $this->generation->stopSequences,
            ],
            'thinking' => [
                'enabled' => $this->thinking->enabled,
                'budget' => $this->thinking->budget,
                'reasoning_effort' => $this->thinking->reasoningEffort,
            ],
            'safety_settings' => [
                'enabled' => $this->safety->enabled,
                'default_threshold' => $this->safety->defaultThreshold,
                'thresholds' => $this->safety->thresholds,
            ],
            'provider_credentials' => $this->providerCredentials,
            'streaming_enabled' => $this->streamingEnabled,
            'debug_mode' => $this->debugMode,
            'max_turns' => $this->maxTurns,
            'disabled_capabilities' => $this->disabledCapabilities,
            'system_prompt' => $this->systemPrompt,
            'master_prompt' => $this->masterPrompt,
            'master_prompt_stateless' => $this->masterPromptStateless,
            'active_tone' => $this->activeTone,
            'agent_id' => $this->agentId,
            'agent_name' => $this->agentName,
            'agent_emoji' => $this->agentEmoji,
            'pricing_input' => $this->pricingInput,
            'pricing_output' => $this->pricingOutput,
            'vertex_region' => $this->vertexRegion,
        ];
    }

    public function isStreamingEffective(): bool
    {
        return $this->streamingEnabled && !\in_array('streaming', $this->disabledCapabilities, true);
    }

    public function isFunctionCallingEnabled(): bool
    {
        return !\in_array('function_calling', $this->disabledCapabilities, true);
    }

    public function withActiveTone(?string $tone): self
    {
        $clone = clone $this;
        // On passe par toArray()/fromArray() car les props sont readonly
        $data = $this->toArray();
        $data['active_tone'] = $tone;

        return self::fromArray($data);
    }

    public function withAgentInfo(?int $agentId, ?string $agentName, ?string $agentEmoji): self
    {
        $data = $this->toArray();
        $data['agent_id'] = $agentId;
        $data['agent_name'] = $agentName;
        $data['agent_emoji'] = $agentEmoji;

        return self::fromArray($data);
    }
}
