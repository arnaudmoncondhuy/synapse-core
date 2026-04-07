<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\PresetValidator;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;

/**
 * Agent de validation de preset LLM.
 *
 * Orchestre le test automatique d'un preset en 3 étapes séquentielles :
 * 1. Vérification de la configuration (provider, credentials, modèle) — sans appel LLM
 * 2. Appel LLM réel avec debug activé (respecte les vrais paramètres du preset, incl. streaming)
 * 3. Analyse IA des résultats — compare la config déclarée vs les paramètres réellement envoyés
 */
class PresetValidatorAgent implements AgentInterface
{
    public function __construct(
        private readonly ChatService $chatService,
        private readonly SynapseDebugLogRepository $debugLogRepo,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly SynapseProviderRepository $providerRepo,
        private readonly ConfigProviderInterface $configProvider,
    ) {
    }

    public function getName(): string
    {
        return 'preset_validator';
    }

    public function getLabel(): string
    {
        return 'Validateur de preset LLM';
    }

    public function getDescription(): string
    {
        return 'Teste un preset LLM et produit un rapport d\'analyse de conformité.';
    }

    /**
     * Exécute l'agent via le contrat unifié.
     *
     * Cet agent étant non-conversationnel, l'entité `SynapseModelPreset` à valider est
     * attendue sous la clé `$options['preset']`. C'est une exception assumée à la règle
     * "tout doit être Messenger-serializable" — `PresetValidatorAgent` est un agent
     * interne au bundle, appelé en synchrone depuis des contrôleurs, jamais via file.
     * Pour une utilisation via Messenger, préférer directement `runAll()` après avoir
     * résolu l'entité dans le handler ({@see \ArnaudMoncondhuy\SynapseCore\MessageHandler\TestModelPresetMessageHandler}).
     *
     * @param array<string, mixed> $options Doit contenir `preset` => SynapseModelPreset
     */
    public function call(Input $input, array $options = []): Output
    {
        $preset = $options['preset'] ?? null;
        if (!$preset instanceof SynapseModelPreset) {
            throw new \InvalidArgumentException('PresetValidatorAgent::call() requires $options["preset"] to be a SynapseModelPreset instance.');
        }

        return Output::ofData($this->runAll($preset));
    }

    /**
     * Exécute une étape du diagnostic.
     *
     * @param array<string, mixed> $report
     */
    public function runStep(int $step, SynapseModelPreset $preset, array &$report): void
    {
        match ($step) {
            1 => $this->executeConfigCheckStep($preset, $report),
            2 => $this->executeLlmCallStep($preset, $report),
            3 => $this->executeAnalysisStep($preset, $report),
            default => throw new \InvalidArgumentException("Étape de validation $step inconnue."),
        };
    }

    /**
     * Récupère d'un coup tous les résultats (utilisé pour l'affichage final).
     *
     * @return array<string, mixed>
     */
    public function runAll(SynapseModelPreset $preset): array
    {
        $report = [];

        for ($i = 1; $i <= 3; ++$i) {
            $this->runStep($i, $preset, $report);
        }

        return $report;
    }

    /**
     * Étape 1 : Validation de la configuration technique.
     *
     * @param array<string, mixed> $report
     */
    private function executeConfigCheckStep(SynapseModelPreset $preset, array &$report): void
    {
        $checks = [];
        $errors = [];

        // Vérification du provider
        $providerName = $preset->getProviderName();
        if (empty($providerName)) {
            $errors[] = 'Aucun provider défini dans le preset';
            $checks['provider_exists'] = false;
            $checks['provider_enabled'] = false;
            $checks['provider_configured'] = false;
        } else {
            $provider = $this->providerRepo->findByName($providerName);
            $checks['provider_exists'] = null !== $provider;

            if (null === $provider) {
                $errors[] = "Provider \"$providerName\" introuvable en base de données";
                $checks['provider_enabled'] = false;
                $checks['provider_configured'] = false;
            } else {
                $checks['provider_enabled'] = $provider->isEnabled();
                $checks['provider_configured'] = $provider->isConfigured();

                if (!$provider->isEnabled()) {
                    $errors[] = 'Provider "'.$provider->getLabel().'" est désactivé';
                } elseif (!$provider->isConfigured()) {
                    $errors[] = 'Provider "'.$provider->getLabel().'" non configuré (clé API manquante)';
                }
            }
        }

        // Vérification du modèle
        $model = $preset->getModel();
        $checks['model_known'] = !empty($model) && $this->capabilityRegistry->isKnownModel($model);
        if (empty($model)) {
            $errors[] = 'Aucun modèle défini dans le preset';
        } elseif (!$this->capabilityRegistry->isKnownModel($model)) {
            $errors[] = "Modèle \"$model\" inconnu dans la registry — il sera quand même testé";
        }

        $report['config_checks'] = $checks;
        $report['config_errors'] = $errors;
        $report['config_ok'] = empty($errors);
        $report['preset_info'] = [
            'name' => $preset->getName(),
            'provider' => $providerName,
            'model' => $model,
            'streaming_enabled' => $preset->isStreamingEnabled(),
            'temperature' => $preset->getGenerationTemperature(),
            'top_p' => $preset->getGenerationTopP(),
            'top_k' => $preset->getGenerationTopK(),
            'max_output_tokens' => $preset->getGenerationMaxOutputTokens(),
        ];
    }

    /**
     * Étape 2 : Test d'appel LLM (Hello world).
     *
     * @param array<string, mixed> $report
     */
    private function executeLlmCallStep(SynapseModelPreset $preset, array &$report): void
    {
        $result = [];
        $syncError = null;

        try {
            $result = $this->chatService->ask(
                'Dis-moi bonjour en une phrase courte.',
                [
                    'agent' => $this->getName(),
                    'preset' => $preset,
                    'stateless' => true,
                    'tools' => [],
                    // Pas de 'streaming' forcé : on respecte le réglage réel du preset
                    // pour valider le comportement de streaming déclaré
                ]
            );
        } catch (\Throwable $e) {
            $syncError = $e->getMessage();
        }

        $report['ai_response'] = $result['answer'] ?? null;
        $report['debug_id'] = $result['debug_id'] ?? null;
        $report['sync_error'] = $syncError; // Gardé intentionnellement dans le rapport final

        // Récupérer les données de debug depuis la DB
        $debugData = [];
        $debugId = $result['debug_id'] ?? null;
        if (!empty($debugId) && is_string($debugId)) {
            $debugLog = $this->debugLogRepo->findByDebugId($debugId);
            $debugData = $debugLog?->getData() ?? [];
        }

        $report['usage_test'] = $debugData['usage'] ?? ($result['usage'] ?? []);
        $report['debug_data_sync'] = $debugData;

        $report['critical_checks'] = [
            'response_not_empty' => !empty($result['answer']),
            'debug_saved_in_db' => !empty($result['debug_id']),
        ];

        // ── Comparaison preset déclaré vs paramètres réellement envoyés à l'API ──
        /** @var array<string, mixed> $debugArray */
        $debugArray = $debugData;
        /** @var array<string, mixed> $actualParams */
        $actualParams = is_array($debugArray['preset_config'] ?? null) ? $debugArray['preset_config'] : [];
        /** @var array<string, mixed> $rawRequest */
        $rawRequest = is_array($debugArray['raw_request_body'] ?? null) ? $debugArray['raw_request_body'] : [];

        // Les paramètres réels peuvent être dans preset_config (config normalisée) ou raw_request_body
        $actualModel = $actualParams['model'] ?? $rawRequest['model'] ?? null;
        $actualTemp = $actualParams['temperature'] ?? $rawRequest['temperature'] ?? null;
        $actualTopP = $actualParams['top_p'] ?? $rawRequest['top_p'] ?? null;
        $actualStreaming = $rawRequest['stream'] ?? $actualParams['streaming'] ?? null;

        $expectedTemp = $preset->getGenerationTemperature();
        $expectedTopP = $preset->getGenerationTopP();
        $expectedStreaming = $preset->isStreamingEnabled();

        $comparison = [];

        $comparison['model'] = [
            'expected' => $preset->getModel(),
            'actual' => is_scalar($actualModel) ? (string) $actualModel : (is_array($actualModel) ? json_encode($actualModel) : null),
            'ok' => is_scalar($actualModel) && strtolower((string) $actualModel) === strtolower($preset->getModel()),
        ];

        if (null !== $actualTemp) {
            $comparison['temperature'] = [
                'expected' => $expectedTemp,
                'actual' => round((float) $actualTemp, 4),
                'ok' => abs((float) $actualTemp - $expectedTemp) < 0.001,
            ];
        }

        if (null !== $actualTopP) {
            $comparison['top_p'] = [
                'expected' => $expectedTopP,
                'actual' => round((float) $actualTopP, 4),
                'ok' => abs((float) $actualTopP - $expectedTopP) < 0.001,
            ];
        }

        if (null !== $actualStreaming) {
            $comparison['streaming'] = [
                'expected' => $expectedStreaming,
                'actual' => (bool) $actualStreaming,
                'ok' => (bool) $actualStreaming === $expectedStreaming,
            ];
        }

        // Vérification thinking si le modèle le supporte
        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());
        if ($caps->supportsThinking) {
            $providerOptions = $preset->getProviderOptions() ?? [];
            $thinkingConfig = $providerOptions['thinking'] ?? null;
            $actualThinking = $rawRequest['thinking'] ?? $rawRequest['reasoning_effort'] ?? null;

            $comparison['thinking'] = [
                'expected' => null !== $thinkingConfig ? json_encode($thinkingConfig) : '(non configuré)',
                'actual' => null !== $actualThinking ? (is_string($actualThinking) ? $actualThinking : json_encode($actualThinking)) : '(absent de la requête)',
                'ok' => (null === $thinkingConfig) === (null === $actualThinking), // Les deux absents = ok
            ];
        }

        $report['params_comparison'] = $comparison;
    }

    /**
     * Étape 3 : Analyse des capacités (Thinking, Tools, Context).
     *
     * @param array<string, mixed> $report
     */
    private function executeAnalysisStep(SynapseModelPreset $preset, array &$report): void
    {
        // Défense supplémentaire : s'assurer que le configProvider n'utilise plus le preset testé
        // (normalement géré par le try/finally de ChatService, mais on double la sécurité)
        $this->configProvider->setOverride(null);

        $debugData = $report['debug_data_sync'] ?? [];
        $syncError = $report['sync_error'] ?? null;
        $syncUsage = $report['usage_test'] ?? [];
        $configErrors = $report['config_errors'] ?? [];

        $presetConfig = $preset->toArray();
        unset($presetConfig['provider_credentials']);
        $presetConfigJson = json_encode($presetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $debugLogData = is_array($debugData) ? $debugData : [];
        $normalizedParamsJson = json_encode($debugLogData['preset_config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $rawRequestJson = json_encode($debugLogData['raw_request_body'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $rawResponse = $debugLogData['raw_api_response'] ?? $debugLogData['raw_api_chunks'] ?? [];
        if (empty($rawResponse) && null !== $syncError) {
            $rawResponse = ['error_from_api_client' => $syncError];
        }
        $rawResponseJson = is_array($rawResponse) ? json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : (string) $rawResponse;

        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());
        $capsJson = json_encode([
            'thinking_supported' => $caps->supportsThinking,
            'safety_settings_supported' => $caps->supportsSafetySettings,
            'top_k_supported' => $caps->supportsTopK,
            'function_calling_supported' => $caps->supportsFunctionCalling,
            'streaming_supported' => $caps->supportsStreaming,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $configErrorsText = '';
        if (!empty($configErrors)) {
            /** @var array<int|string, string> $configErrorsArray */
            $configErrorsArray = is_array($configErrors) ? $configErrors : [];
            $configErrorsText = "## ⚠️ Erreurs de configuration détectées\n"
                .implode("\n", array_map(fn ($e) => '- '.(string) $e, $configErrorsArray))
                ."\n\n";
        }

        $syncUsageArray = is_array($syncUsage) ? $syncUsage : [];
        /** @var array<string, mixed> $syncUsageArrayCount */
        $syncUsageArrayCount = is_array($syncUsage) ? $syncUsage : [];
        $tokensOk = ((is_numeric($syncUsageArrayCount['prompt_tokens'] ?? null) ? (int) $syncUsageArrayCount['prompt_tokens'] : 0) + (is_numeric($syncUsageArrayCount['completion_tokens'] ?? null) ? (int) $syncUsageArrayCount['completion_tokens'] : 0) + (is_numeric($syncUsageArrayCount['thinking_tokens'] ?? null) ? (int) $syncUsageArrayCount['thinking_tokens'] : 0)) === (is_numeric($syncUsageArrayCount['total_tokens'] ?? null) ? (int) $syncUsageArrayCount['total_tokens'] : 0);

        $analysisPrompt = sprintf(
            "Tu es un agent de validation du système Synapse LLM. Analyse les données du test d'un preset.\n\n"
                ."OBJECTIF : Vérifier que les paramètres réellement envoyés à l'API correspondent à ce qui est configuré dans le preset.\n"
                ."IMPORTANT : Si la réponse brute contient une erreur (ex: 400 Bad Request, 401 Unauthorized), explique la cause probable.\n"
                ."IMPORTANT : Vérifie les incohérences de capacités entre le preset et le modèle (ex: thinking activé sur un modèle qui ne le supporte pas).\n\n"
                .'%s'
                ."## 0. Capacités déclarées du modèle\n```json\n%s\n```\n\n"
                ."## 1. Configuration du preset (déclarée)\n```json\n%s\n```\n\n"
                ."## 2. Paramètres normalisés envoyés (debug)\n```json\n%s\n```\n\n"
                ."## 3. Requête brute envoyée à l'API\n```json\n%s\n```\n\n"
                ."## 4. Réponse brute reçue de l'API\n```json\n%s\n```\n\n"
                ."## 5. Consommation tokens\n- Prompt: %d, Completion: %d, Thinking: %d, Total: %d (%s)\n\n"
                ."Retourne un rapport Markdown concis avec ces sections :\n"
                ."### ✅ Points conformes\n### ⚠️ Anomalies détectées\n### 💡 Recommandations\n### Conclusion",
            $configErrorsText,
            $capsJson,
            $presetConfigJson,
            $normalizedParamsJson,
            $rawRequestJson,
            $rawResponseJson,
            (int) ($syncUsageArray['prompt_tokens'] ?? 0),
            (int) ($syncUsageArray['completion_tokens'] ?? 0),
            (int) ($syncUsageArray['thinking_tokens'] ?? 0),
            (int) ($syncUsageArray['total_tokens'] ?? 0),
            $tokensOk ? '✅ cohérent' : '❌ incohérent',
        );

        $analysisResult = [];
        try {
            $analysisResult = $this->chatService->ask($analysisPrompt, [
                'agent' => $this->getName(),
                'stateless' => true,
                'tools' => [],
                // Pas de 'preset' : utilise le preset actif pour l'analyse
            ]);
        } catch (\Throwable $e) {
            $report['analysis'] = 'Analyse IA indisponible : '.$e->getMessage();
            $report['all_critical_ok'] = ($report['config_ok'] ?? true)
                && !in_array(false, is_array($report['critical_checks'] ?? null) ? $report['critical_checks'] : [], true);
            unset($report['debug_data_sync']);

            return;
        }

        $report['analysis'] = $analysisResult['answer'] ?? 'Analyse indisponible.';

        // Statut global : config OK + réponse non vide
        $criticalChecks = is_array($report['critical_checks'] ?? null) ? (array) $report['critical_checks'] : [];
        $configOk = (bool) ($report['config_ok'] ?? true);
        $report['all_critical_ok'] = $configOk && !in_array(false, $criticalChecks, true);

        // Nettoyage des données internes volumineuses (sync_error est intentionnellement gardé)
        unset($report['debug_data_sync']);
    }
}
