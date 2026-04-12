<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use Symfony\Component\Yaml\Yaml;

/**
 * Registre des profils de capacités par modèle LLM.
 *
 * Charge les capacités depuis des fichiers YAML situés dans Provider/{ProviderName}/models.yaml.
 * Permet à chaque client LLM de savoir quels paramètres envoyer à l'API.
 */
class ModelCapabilityRegistry
{
    /** @var array<string, array<string, mixed>> Modèles bruts chargés depuis les fichiers YAML */
    private array $models = [];

    public function __construct()
    {
        $this->loadModels();
    }

    /**
     * Charge tous les fichiers YAML de configuration des modèles (un par provider).
     */
    private function loadModels(): void
    {
        // __DIR__ = src/Engine → dirname(__DIR__) = src/ → Provider/*/models.yaml
        $providerDir = dirname(__DIR__).'/Provider';

        if (!is_dir($providerDir)) {
            // Tentative de résolution alternative si appelé depuis une application hôte
            $providerDir = dirname(__DIR__, 4).'/vendor/arnaudmoncondhuy/synapse-core/src/Provider';
        }

        if (!is_dir($providerDir)) {
            return;
        }

        $files = glob($providerDir.'/*/models.yaml');
        if (!$files) {
            return;
        }

        foreach ($files as $file) {
            try {
                $config = Yaml::parseFile($file);
                if (is_array($config) && isset($config['models']) && is_array($config['models'])) {
                    /** @var array<string, array<string, mixed>> $models */
                    $models = $config['models'];
                    $this->models = array_merge($this->models, $models);
                }
            } catch (\Throwable $e) {
                // En cas d'erreur sur un fichier, on continue pour ne pas bloquer tout le registre
                // Dans un contexte de prod, on pourrait logguer ici.
            }
        }
    }

    /**
     * Capacités par défaut pour les modèles non référencés.
     */
    private const DEFAULTS = [
        'provider' => 'unknown',
        'range' => 'balanced',
        // Phase 1.5 — convention supports_*
        'supports_thinking' => false,
        'supports_safety_settings' => false,
        'supports_top_k' => false,
        'supports_function_calling' => true,
        'supports_streaming' => true,
        'supports_system_prompt' => true,
        // Contexte (context_window supprimé — utiliser max_input_tokens)
        'max_input_tokens' => null,
        'max_output_tokens' => null,
        // Tarification
        'pricing_input' => null,
        'pricing_output' => null,
        'pricing_output_image' => null,
        // Phase 1 — Modalités
        'supports_vision' => false,
        'supports_parallel_tool_calls' => false,
        'supports_response_schema' => false,
        'supports_text_generation' => true,
        'supports_embedding' => false,
        'supports_image_generation' => false,
        // Lifecycle
        'deprecated_at' => null,
    ];

    /**
     * Retourne le profil de capacités d'un modèle.
     */
    public function getCapabilities(string $model): ModelCapabilities
    {
        $raw = $this->models[$model] ?? self::DEFAULTS;
        /** @var array<string, mixed> $data */
        $data = is_array($raw) ? $raw : self::DEFAULTS;

        return new ModelCapabilities(
            model: $model,
            provider: is_string($data['provider'] ?? null) ? (string) $data['provider'] : 'unknown',
            range: ModelRange::fromString(is_string($data['range'] ?? null) ? $data['range'] : null),
            dimensions: is_array($data['dimensions'] ?? null) ? array_map(fn ($v) => is_numeric($v) ? (int) $v : 0, (array) $data['dimensions']) : [],
            currency: is_string($data['currency'] ?? null) ? (string) $data['currency'] : 'USD',
            // Phase 1.5 — convention supports_*
            supportsThinking: (bool) ($data['supports_thinking'] ?? false),
            supportsSafetySettings: (bool) ($data['supports_safety_settings'] ?? false),
            supportsTopK: (bool) ($data['supports_top_k'] ?? false),
            supportsFunctionCalling: (bool) ($data['supports_function_calling'] ?? true),
            supportsStreaming: (bool) ($data['supports_streaming'] ?? true),
            supportsSystemPrompt: (bool) ($data['supports_system_prompt'] ?? true),
            pricingInput: is_numeric($data['pricing_input'] ?? null) ? (float) $data['pricing_input'] : null,
            pricingOutput: is_numeric($data['pricing_output'] ?? null) ? (float) $data['pricing_output'] : null,
            pricingOutputImage: is_numeric($data['pricing_output_image'] ?? null) ? (float) $data['pricing_output_image'] : null,
            // Phase 1 — Contexte asymétrique
            maxInputTokens: is_numeric($data['max_input_tokens'] ?? null) ? (int) $data['max_input_tokens'] : null,
            maxOutputTokens: is_numeric($data['max_output_tokens'] ?? null) ? (int) $data['max_output_tokens'] : null,
            // Phase 1 — Modalités
            supportsVision: (bool) ($data['supports_vision'] ?? false),
            supportsParallelToolCalls: (bool) ($data['supports_parallel_tool_calls'] ?? false),
            supportsResponseSchema: (bool) ($data['supports_response_schema'] ?? false),
            supportsTextGeneration: (bool) ($data['supports_text_generation'] ?? true),
            supportsEmbedding: (bool) ($data['supports_embedding'] ?? false),
            supportsImageGeneration: (bool) ($data['supports_image_generation'] ?? false),
            // Lifecycle
            deprecatedAt: isset($data['deprecated_at']) && is_string($data['deprecated_at']) ? $data['deprecated_at'] : null,
            // Attachments
            acceptedMimeTypes: isset($data['accepted_mime_types']) && is_array($data['accepted_mime_types']) ? array_values(array_filter($data['accepted_mime_types'], 'is_string')) : [],
            // Provider-specific
            providerRegions: isset($data['provider_regions']) && is_array($data['provider_regions']) ? array_values(array_filter($data['provider_regions'], 'is_string')) : [],
            // RGPD
            rgpdRisk: isset($data['rgpd_risk']) && is_string($data['rgpd_risk']) ? $data['rgpd_risk'] : null,
        );
    }

    public function supports(string $model, string $capability): bool
    {
        return $this->getCapabilities($model)->supports($capability);
    }

    /**
     * Liste tous les modèles référencés dans le registre.
     *
     * @return string[]
     */
    public function getKnownModels(): array
    {
        return array_keys($this->models);
    }

    /**
     * Liste les modèles disponibles pour un provider donné.
     *
     * @return string[]
     */
    public function getModelsForProvider(string $provider): array
    {
        $result = [];
        foreach ($this->models as $modelId => $data) {
            if (($data['provider'] ?? '') === $provider) {
                $result[] = $modelId;
            }
        }

        return $result;
    }

    /**
     * Retourne le premier modèle disponible pour un provider donné (pour les fallbacks).
     */
    public function getFirstModelForProvider(string $provider): string
    {
        return $this->getModelsForProvider($provider)[0] ?? '';
    }

    public function isKnownModel(string $model): bool
    {
        return isset($this->models[$model]);
    }

    /**
     * Retourne une map complète modelId → capabilities (array) pour tous les modèles connus.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAllCapabilitiesMap(): array
    {
        $result = [];
        foreach ($this->getKnownModels() as $modelId) {
            $caps = $this->getCapabilities($modelId);
            $result[$modelId] = [
                'provider' => $caps->provider,
                'range' => $caps->range->value,
                'dimensions' => $caps->dimensions,
                'supportsTextGeneration' => $caps->supportsTextGeneration,
                'supportsEmbedding' => $caps->supportsEmbedding,
                'supportsImageGeneration' => $caps->supportsImageGeneration,
                'supportsThinking' => $caps->supportsThinking,
                'supportsSafetySettings' => $caps->supportsSafetySettings,
                'supportsTopK' => $caps->supportsTopK,
                'supportsFunctionCalling' => $caps->supportsFunctionCalling,
                'supportsStreaming' => $caps->supportsStreaming,
                'supportsVision' => $caps->supportsVision,
                'supportsParallelToolCalls' => $caps->supportsParallelToolCalls,
                'supportsResponseSchema' => $caps->supportsResponseSchema,
                'maxInputTokens' => $caps->maxInputTokens,
                'maxOutputTokens' => $caps->maxOutputTokens,
                'deprecatedAt' => $caps->deprecatedAt,
                'providerRegions' => $caps->providerRegions,
                'rgpdRisk' => $caps->rgpdRisk,
                'pricingInput' => $caps->pricingInput,
                'pricingOutput' => $caps->pricingOutput,
                'pricingOutputImage' => $caps->pricingOutputImage,
            ];
        }

        return $result;
    }
}
