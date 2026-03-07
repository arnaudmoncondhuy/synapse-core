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
        'thinking' => false,
        'safety_settings' => false,
        'top_k' => false,
        'function_calling' => true,
        'streaming' => true,
        'system_prompt' => true,
        'context_window' => null,
        'pricing_input' => null,
        'pricing_output' => null,
        // Phase 1
        'max_input_tokens' => null,
        'max_output_tokens' => null,
        'supports_vision' => false,
        'supports_parallel_tool_calls' => false,
        'supports_response_schema' => false,
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
            thinking: (bool) ($data['thinking'] ?? false),
            safetySettings: (bool) ($data['safety_settings'] ?? false),
            topK: (bool) ($data['top_k'] ?? false),
            functionCalling: (bool) ($data['function_calling'] ?? true),
            streaming: (bool) ($data['streaming'] ?? true),
            systemPrompt: (bool) ($data['system_prompt'] ?? true),
            contextWindow: is_numeric($data['context_window'] ?? null) ? (int) $data['context_window'] : null,
            pricingInput: is_numeric($data['pricing_input'] ?? null) ? (float) $data['pricing_input'] : null,
            pricingOutput: is_numeric($data['pricing_output'] ?? null) ? (float) $data['pricing_output'] : null,
            modelId: isset($data['model_id']) && is_string($data['model_id']) ? (string) $data['model_id'] : null,
            dimensions: is_array($data['dimensions'] ?? null) ? array_map(fn($v) => (int) $v, (array) $data['dimensions']) : [],
            // Phase 1
            maxInputTokens: is_numeric($data['max_input_tokens'] ?? null) ? (int) $data['max_input_tokens'] : null,
            maxOutputTokens: is_numeric($data['max_output_tokens'] ?? null) ? (int) $data['max_output_tokens'] : null,
            supportsVision: (bool) ($data['supports_vision'] ?? false),
            supportsParallelToolCalls: (bool) ($data['supports_parallel_tool_calls'] ?? false),
            supportsResponseSchema: (bool) ($data['supports_response_schema'] ?? false),
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
