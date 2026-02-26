<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chat;

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
    /** @var array<string, array> Cache local des modèles chargés */
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
        // Essayer d'abord le chemin dans Infrastructure (après refactorisation)
        // __DIR__ = src/Core/Chat, donc dirname(__DIR__, 3) = bundle root
        $configDir = dirname(__DIR__, 3) . '/src/Infrastructure/Resources/config/models';

        // Fallback vers Core/Resources (ancien chemin)
        if (!is_dir($configDir)) {
            $configDir = dirname(__DIR__, 3) . '/src/Core/Resources/config/models';
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
                if (isset($config['models']) && is_array($config['models'])) {
                    $this->models = array_merge($this->models, $config['models']);
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
        'provider'        => 'unknown',
        'type'            => 'chat',
        'thinking'        => false,
        'safety_settings' => false,
        'top_k'           => false,
        'function_calling' => true,
        'streaming'       => true,
        'system_prompt'   => true,
        'context_window'  => null,
        'pricing_input'   => null,
        'pricing_output'  => null,
    ];

    /**
     * Retourne le profil de capacités d'un modèle.
     */
    public function getCapabilities(string $model): ModelCapabilities
    {
        $data = $this->models[$model] ?? self::DEFAULTS;

        return new ModelCapabilities(
            model: $model,
            provider: $data['provider'],
            type: $data['type'] ?? 'chat',
            thinking: $data['thinking'] ?? false,
            safetySettings: $data['safety_settings'] ?? false,
            topK: $data['top_k'] ?? false,
            functionCalling: $data['function_calling'] ?? true,
            streaming: $data['streaming'] ?? true,
            systemPrompt: $data['system_prompt'] ?? true,
            contextWindow: isset($data['context_window']) ? (int) $data['context_window'] : null,
            pricingInput: isset($data['pricing_input']) ? (float) $data['pricing_input'] : null,
            pricingOutput: isset($data['pricing_output']) ? (float) $data['pricing_output'] : null,
            modelId: $data['model_id'] ?? null,
            dimensions: $data['dimensions'] ?? [],
        );
    }

    public function supports(string $model, string $capability): bool
    {
        return $this->getCapabilities($model)->supports($capability);
    }

    /**
     * Liste tous les modèles référencés dans le registre.
     * @return string[]
     */
    public function getKnownModels(): array
    {
        return array_keys($this->models);
    }

    /**
     * Liste les modèles disponibles pour un provider donné.
     * @return string[]
     */
    public function getModelsForProvider(string $provider): array
    {
        return array_keys(array_filter(
            $this->models,
            fn(array $caps) => ($caps['provider'] ?? '') === $provider
        ));
    }

    public function isKnownModel(string $model): bool
    {
        return isset($this->models[$model]);
    }
}
