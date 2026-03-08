<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use Symfony\Component\Yaml\Yaml;

/**
 * Registre des profils de capacités par modèle LLM.
 *
 * Charge les capacités depuis des fichiers YAML situés dans Resources/config/models/.
 * Permet à chaque client (GeminiClient, OvhAiClient…) de savoir quels
 * paramètres envoyer à l'API.
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
     * Charge tous les fichiers YAML du dossier de configuration des modèles.
     */
    private function loadModels(): void
    {
        // Chemin vers les configurations YAML des modèles après refactorisation
        // __DIR__ = src/Engine, donc dirname(__DIR__, 2) = packages/core
        $configDir = dirname(__DIR__, 2) . '/src/Resources/config/models';

        if (!is_dir($configDir)) {
            // Tentative de résolution alternative si appelé depuis une application hôte
            $configDir = dirname(__DIR__, 5) . '/vendor/arnaudmoncondhuy/synapse-core/src/Resources/config/models';
        }

        if (!is_dir($configDir)) {
            return;
        }

        $files = glob($configDir . '/*.yaml');
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
        'type' => 'chat',
        // Phase 1.5 — convention supports_*
        'supports_thinking' => false,
        'supports_safety_settings' => false,
        'supports_top_k' => false,
        'supports_function_calling' => true,
        'supports_streaming' => true,
        'supports_system_prompt' => true,
        // Contexte
        'context_window' => null,
        'max_input_tokens' => null,
        'max_output_tokens' => null,
        // Tarification
        'pricing_input' => null,
        'pricing_output' => null,
        // Phase 1 — Modalités
        'supports_vision' => false,
        'supports_parallel_tool_calls' => false,
        'supports_response_schema' => false,
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
            type: is_string($data['type'] ?? null) ? (string) $data['type'] : 'chat',
            dimensions: is_array($data['dimensions'] ?? null) ? array_map(fn($v) => (int) $v, (array) $data['dimensions']) : [],
            // Phase 1.5 — convention supports_* (compat: ancienne clé sans préfixe acceptée en fallback)
            supportsThinking: (bool) ($data['supports_thinking'] ?? $data['thinking'] ?? false),
            supportsSafetySettings: (bool) ($data['supports_safety_settings'] ?? $data['safety_settings'] ?? false),
            supportsTopK: (bool) ($data['supports_top_k'] ?? $data['top_k'] ?? false),
            supportsFunctionCalling: (bool) ($data['supports_function_calling'] ?? $data['function_calling'] ?? true),
            supportsStreaming: (bool) ($data['supports_streaming'] ?? $data['streaming'] ?? true),
            supportsSystemPrompt: (bool) ($data['supports_system_prompt'] ?? $data['system_prompt'] ?? true),
            // Contexte
            contextWindow: is_numeric($data['context_window'] ?? null) ? (int) $data['context_window'] : null,
            pricingInput: is_numeric($data['pricing_input'] ?? null) ? (float) $data['pricing_input'] : null,
            pricingOutput: is_numeric($data['pricing_output'] ?? null) ? (float) $data['pricing_output'] : null,
            // Phase 1 — Contexte asymétrique
            maxInputTokens: is_numeric($data['max_input_tokens'] ?? null) ? (int) $data['max_input_tokens'] : null,
            maxOutputTokens: is_numeric($data['max_output_tokens'] ?? null) ? (int) $data['max_output_tokens'] : null,
            // Phase 1 — Modalités
            supportsVision: (bool) ($data['supports_vision'] ?? false),
            supportsParallelToolCalls: (bool) ($data['supports_parallel_tool_calls'] ?? false),
            supportsResponseSchema: (bool) ($data['supports_response_schema'] ?? false),
            // Lifecycle
            deprecatedAt: isset($data['deprecated_at']) && is_string($data['deprecated_at']) ? $data['deprecated_at'] : null,
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

    public function isKnownModel(string $model): bool
    {
        return isset($this->models[$model]);
    }
}
