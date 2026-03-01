<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Agent\PresetValidator;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;

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
        private ChatService $chatService,
        private SynapseDebugLogRepository $debugLogRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private SynapseProviderRepository $providerRepo,
        private ConfigProviderInterface $configProvider,
    ) {}

    public function getName(): string
    {
        return 'preset_validator';
    }

    public function getDescription(): string
    {
        return 'Teste un preset LLM et produit un rapport d\'analyse de conformité.';
    }

    /**
     * @param array $input ['preset' => SynapsePreset]
     */
    public function run(array $input): array
    {
        return $this->runAll($input['preset']);
    }

    /**
     * Exécute les 3 étapes de validation en séquence et retourne le rapport complet.
     * À appeler depuis le contrôleur dans une seule requête HTTP.
     */
    public function runAll(SynapsePreset $preset): array
    {
        $report = [];

        // Étape 1 : Vérification config (sans appel LLM — rapide)
        $this->executeConfigCheckStep($preset, $report);

        // Étape 2 : Appel LLM réel avec debug
        $this->executeLlmCallStep($preset, $report);

        // Étape 3 : Analyse IA (utilise le preset actif, pas le preset testé)
        $this->executeAnalysisStep($preset, $report);

        return $report;
    }

    /**
     * Étape 1 : Vérifie le provider, les credentials et le modèle sans faire d'appel LLM.
     */
    private function executeConfigCheckStep(SynapsePreset $preset, array &$report): void
    {
        $checks = [];
        $errors = [];

        // Vérification du provider
        $providerName = $preset->getProviderName();
        if (empty($providerName)) {
            $errors[] = 'Aucun provider défini dans le preset';
            $checks['provider_exists']     = false;
            $checks['provider_enabled']    = false;
            $checks['provider_configured'] = false;
        } else {
            $provider = $this->providerRepo->findByName($providerName);
            $checks['provider_exists'] = $provider !== null;

            if ($provider === null) {
                $errors[] = "Provider \"$providerName\" introuvable en base de données";
                $checks['provider_enabled']    = false;
                $checks['provider_configured'] = false;
            } else {
                $checks['provider_enabled']    = $provider->isEnabled();
                $checks['provider_configured'] = $provider->isConfigured();

                if (!$provider->isEnabled()) {
                    $errors[] = 'Provider "' . $provider->getLabel() . '" est désactivé';
                } elseif (!$provider->isConfigured()) {
                    $errors[] = 'Provider "' . $provider->getLabel() . '" non configuré (clé API manquante)';
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
        $report['config_ok']     = empty($errors);
        $report['preset_info']   = [
            'name'               => $preset->getName(),
            'provider'           => $providerName,
            'model'              => $model,
            'streaming_enabled'  => $preset->isStreamingEnabled(),
            'temperature'        => $preset->getGenerationTemperature(),
            'top_p'              => $preset->getGenerationTopP(),
            'top_k'              => $preset->getGenerationTopK(),
            'max_output_tokens'  => $preset->getGenerationMaxOutputTokens(),
        ];
    }

    /**
     * Étape 2 : Appel LLM réel avec les vrais paramètres du preset (streaming inclus) et debug activé.
     * Construit également la comparaison preset déclaré vs paramètres réellement envoyés à l'API.
     */
    private function executeLlmCallStep(SynapsePreset $preset, array &$report): void
    {
        $result    = [];
        $syncError = null;

        try {
            $result = $this->chatService->ask(
                'Dis-moi bonjour en une phrase courte.',
                [
                    'preset'    => $preset,
                    'debug'     => true,
                    'stateless' => true,
                    'tools'     => [],
                    // Pas de 'streaming' forcé : on respecte le réglage réel du preset
                    // pour valider le comportement de streaming déclaré
                ]
            );
        } catch (\Throwable $e) {
            $syncError = $e->getMessage();
        }

        $report['ai_response'] = $result['answer'] ?? null;
        $report['debug_id']    = $result['debug_id'] ?? null;
        $report['sync_error']  = $syncError; // Gardé intentionnellement dans le rapport final

        // Récupérer les données de debug depuis la DB
        $debugData = [];
        if (!empty($result['debug_id'])) {
            $debugLog  = $this->debugLogRepo->findByDebugId($result['debug_id']);
            $debugData = $debugLog?->getData() ?? [];
        }

        $report['usage_test']      = $debugData['usage'] ?? ($result['usage'] ?? []);
        $report['debug_data_sync'] = $debugData;

        $report['critical_checks'] = [
            'response_not_empty' => !empty($result['answer']),
            'debug_saved_in_db'  => !empty($result['debug_id']),
        ];

        // ── Comparaison preset déclaré vs paramètres réellement envoyés à l'API ──
        $actualParams = $debugData['preset_config'] ?? [];
        $rawRequest   = $debugData['raw_request_body'] ?? [];

        // Les paramètres réels peuvent être dans preset_config (config normalisée) ou raw_request_body
        $actualModel       = $actualParams['model']       ?? $rawRequest['model']       ?? null;
        $actualTemp        = $actualParams['temperature']  ?? $rawRequest['temperature']  ?? null;
        $actualTopP        = $actualParams['top_p']        ?? $rawRequest['top_p']        ?? null;
        $actualStreaming    = $rawRequest['stream']         ?? $actualParams['streaming']  ?? null;

        $expectedTemp        = $preset->getGenerationTemperature();
        $expectedTopP        = $preset->getGenerationTopP();
        $expectedStreaming    = $preset->isStreamingEnabled();

        $comparison = [];

        $comparison['model'] = [
            'expected' => $preset->getModel(),
            'actual'   => $actualModel,
            'ok'       => $actualModel !== null && strtolower((string) $actualModel) === strtolower($preset->getModel()),
        ];

        if ($actualTemp !== null) {
            $comparison['temperature'] = [
                'expected' => $expectedTemp,
                'actual'   => round((float) $actualTemp, 4),
                'ok'       => abs((float) $actualTemp - $expectedTemp) < 0.001,
            ];
        }

        if ($actualTopP !== null) {
            $comparison['top_p'] = [
                'expected' => $expectedTopP,
                'actual'   => round((float) $actualTopP, 4),
                'ok'       => abs((float) $actualTopP - $expectedTopP) < 0.001,
            ];
        }

        if ($actualStreaming !== null) {
            $comparison['streaming'] = [
                'expected' => $expectedStreaming,
                'actual'   => (bool) $actualStreaming,
                'ok'       => (bool) $actualStreaming === $expectedStreaming,
            ];
        }

        // Vérification thinking si le modèle le supporte
        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());
        if ($caps->thinking) {
            $providerOptions = $preset->getProviderOptions() ?? [];
            $thinkingConfig  = $providerOptions['thinking'] ?? null;
            $actualThinking  = $rawRequest['thinking'] ?? $rawRequest['reasoning_effort'] ?? null;

            $comparison['thinking'] = [
                'expected' => $thinkingConfig !== null ? json_encode($thinkingConfig) : '(non configuré)',
                'actual'   => $actualThinking !== null ? json_encode($actualThinking) : '(absent de la requête)',
                'ok'       => ($thinkingConfig === null) === ($actualThinking === null), // Les deux absents = ok
            ];
        }

        $report['params_comparison'] = $comparison;
    }

    /**
     * Étape 3 : Analyse IA des résultats avec un appel sur le preset ACTIF (pas le preset testé).
     * L'override est explicitement réinitialisé avant cet appel comme défense supplémentaire.
     */
    private function executeAnalysisStep(SynapsePreset $preset, array &$report): void
    {
        // Défense supplémentaire : s'assurer que le configProvider n'utilise plus le preset testé
        // (normalement géré par le try/finally de ChatService, mais on double la sécurité)
        $this->configProvider->setOverride(null);

        $debugData    = $report['debug_data_sync'] ?? [];
        $syncError    = $report['sync_error'] ?? null;
        $syncUsage    = $report['usage_test'] ?? [];
        $configErrors = $report['config_errors'] ?? [];

        $presetConfig = $preset->toArray();
        unset($presetConfig['provider_credentials']);
        $presetConfigJson     = json_encode($presetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $normalizedParamsJson = json_encode($debugData['preset_config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $rawRequestJson       = json_encode($debugData['raw_request_body'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $rawResponse = $debugData['raw_api_response'] ?? $debugData['raw_api_chunks'] ?? [];
        if (empty($rawResponse) && $syncError !== null) {
            $rawResponse = ['error_from_api_client' => $syncError];
        }
        $rawResponseJson = json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $caps     = $this->capabilityRegistry->getCapabilities($preset->getModel());
        $capsJson = json_encode([
            'thinking_supported'          => $caps->thinking,
            'safety_settings_supported'   => $caps->safetySettings,
            'top_k_supported'             => $caps->topK,
            'function_calling_supported'  => $caps->functionCalling,
            'streaming_supported'         => $caps->streaming,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $configErrorsText = '';
        if (!empty($configErrors)) {
            $configErrorsText = "## ⚠️ Erreurs de configuration détectées\n"
                . implode("\n", array_map(fn ($e) => "- $e", $configErrors))
                . "\n\n";
        }

        $tokensOk = (($syncUsage['prompt_tokens'] ?? 0) + ($syncUsage['completion_tokens'] ?? 0) + ($syncUsage['thinking_tokens'] ?? 0)) === ($syncUsage['total_tokens'] ?? 0);

        $analysisPrompt = sprintf(
            "Tu es un agent de validation du système Synapse LLM. Analyse les données du test d'un preset.\n\n"
                . "OBJECTIF : Vérifier que les paramètres réellement envoyés à l'API correspondent à ce qui est configuré dans le preset.\n"
                . "IMPORTANT : Si la réponse brute contient une erreur (ex: 400 Bad Request, 401 Unauthorized), explique la cause probable.\n"
                . "IMPORTANT : Vérifie les incohérences de capacités entre le preset et le modèle (ex: thinking activé sur un modèle qui ne le supporte pas).\n\n"
                . "%s"
                . "## 0. Capacités déclarées du modèle\n```json\n%s\n```\n\n"
                . "## 1. Configuration du preset (déclarée)\n```json\n%s\n```\n\n"
                . "## 2. Paramètres normalisés envoyés (debug)\n```json\n%s\n```\n\n"
                . "## 3. Requête brute envoyée à l'API\n```json\n%s\n```\n\n"
                . "## 4. Réponse brute reçue de l'API\n```json\n%s\n```\n\n"
                . "## 5. Consommation tokens\n- Prompt: %d, Completion: %d, Thinking: %d, Total: %d (%s)\n\n"
                . "Retourne un rapport Markdown concis avec ces sections :\n"
                . "### ✅ Points conformes\n### ⚠️ Anomalies détectées\n### 💡 Recommandations\n### Conclusion",
            $configErrorsText,
            $capsJson,
            $presetConfigJson,
            $normalizedParamsJson,
            $rawRequestJson,
            $rawResponseJson,
            $syncUsage['prompt_tokens'] ?? 0,
            $syncUsage['completion_tokens'] ?? 0,
            $syncUsage['thinking_tokens'] ?? 0,
            $syncUsage['total_tokens'] ?? 0,
            $tokensOk ? '✅ cohérent' : '❌ incohérent',
        );

        $analysisResult = [];
        try {
            $analysisResult = $this->chatService->ask($analysisPrompt, [
                'stateless' => true,
                'debug'     => false,
                'tools'     => [],
                // Pas de 'preset' : utilise le preset actif pour l'analyse
            ]);
        } catch (\Throwable $e) {
            $report['analysis'] = 'Analyse IA indisponible : ' . $e->getMessage();
            $report['all_critical_ok'] = ($report['config_ok'] ?? true)
                && !in_array(false, $report['critical_checks'] ?? [], true);
            unset($report['debug_data_sync']);
            return;
        }

        $report['analysis'] = $analysisResult['answer'] ?? 'Analyse indisponible.';

        // Statut global : config OK + réponse non vide
        $criticalChecks = $report['critical_checks'] ?? [];
        $configOk       = $report['config_ok'] ?? true;
        $report['all_critical_ok'] = $configOk && !in_array(false, $criticalChecks, true);

        // Nettoyage des données internes volumineuses (sync_error est intentionnellement gardé)
        unset($report['debug_data_sync']);
    }
}
