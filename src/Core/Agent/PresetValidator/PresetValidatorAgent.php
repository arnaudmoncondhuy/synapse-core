<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Agent\PresetValidator;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService;

/**
 * Agent de validation de preset LLM.
 *
 * Orchestre le test automatique d'un preset en deux appels LLM :
 * 1. Appel de test : message simple avec le preset cible
 * 2. Appel d'analyse : LLM autonome analyse 4 JSONs bruts (config attendue, paramètres normalisés,
 *    requête brute, réponse brute)
 *
 * Produit un rapport Markdown structuré avec ✅ Points conformes, ⚠️ Anomalies, Conclusion.
 */
class PresetValidatorAgent implements AgentInterface
{
    public function __construct(
        private ChatService $chatService,
        private SynapseDebugLogRepository $debugLogRepo,
        private \ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry $capabilityRegistry,
    ) {}

    public function getName(): string
    {
        return 'preset_validator';
    }

    public function getDescription(): string
    {
        return 'Teste un preset LLM et produit un rapport d\'analyse de conformité '
            . '(paramètres envoyés à l\'API vs. configuration attendue).';
    }

    /**
     * @param array $input ['preset' => SynapsePreset]
     */
    public function run(array $input): array
    {
        $preset = $input['preset'];
        $report = ['status' => 'processing', 'progress' => 0];

        // Execute all steps sequentially for a full run
        $this->runStep(1, $preset, $report);
        $this->runStep(2, $preset, $report);
        $this->runStep(3, $preset, $report);

        $report['status'] = 'completed';
        $report['progress'] = 100;

        return $report;
    }

    /**
     * Executes a specific step of the validation process.
     * 
     * Step 1: Synchronous test call
     * Step 2: Streaming test call (optional)
     * Step 3: LLM Analysis of results
     */
    public function runStep(int $step, SynapsePreset $preset, array &$report): void
    {
        $report['preset'] = $preset;

        switch ($step) {
            case 1:
                $this->executeSyncStep($preset, $report);
                break;
            case 2:
                $this->executeStreamingStep($preset, $report);
                break;
            case 3:
                $this->executeAnalysisStep($preset, $report);
                break;
        }
    }

    public function getStepLabel(int $step): string
    {
        return match ($step) {
            1 => 'Appel synchrone de test...',
            2 => 'Appel streaming de test...',
            3 => 'Analyse des résultats par l\'IA...',
            default => 'Traitement...',
        };
    }

    private function executeSyncStep(SynapsePreset $preset, array &$report): void
    {
        $result = [];
        $syncError = null;
        try {
            $result = $this->chatService->ask(
                'Dis-moi bonjour en une phrase courte.',
                [
                    'preset'      => $preset,
                    'debug'       => true,
                    'stateless'   => true,
                    'tools'       => [],
                ]
            );
        } catch (\Throwable $e) {
            $syncError = $e->getMessage();
        }

        $report['ai_response'] = $result['answer'] ?? null;
        $report['debug_id'] = $result['debug_id'] ?? null;
        $report['sync_error'] = $syncError;

        $debugData = [];
        if (!empty($result['debug_id'])) {
            $debugLog = $this->debugLogRepo->findByDebugId($result['debug_id']);
            $debugData = $debugLog?->getData() ?? [];
        }
        $report['usage_test'] = $debugData['usage'] ?? [];
        $report['debug_data_sync'] = $debugData; // Store for analysis step

        $report['critical_checks'] = $report['critical_checks'] ?? [];
        $report['critical_checks']['response_not_empty'] = !empty($result['answer']);
        $report['critical_checks']['debug_saved_in_db'] = !empty($result['debug_id']);
    }

    private function executeStreamingStep(SynapsePreset $preset, array &$report): void
    {
        $presetConfig = $preset->toArray();
        $isStreamingEnabled = $presetConfig['streaming_enabled'] ?? false;
        $report['critical_checks']['streaming_enabled'] = $isStreamingEnabled;

        if (!$isStreamingEnabled) {
            $report['critical_checks']['streaming_works'] = false;
            return;
        }

        $resultStreaming = null;
        $streamingWorks = false;
        try {
            $resultStreaming = $this->chatService->ask(
                'Dis-moi bonjour en une phrase courte.',
                [
                    'preset'      => $preset,
                    'debug'       => true,
                    'stateless'   => true,
                    'tools'       => [],
                    'streaming'   => true,
                ]
            );
            $streamingWorks = !empty($resultStreaming['answer']);
        } catch (\Throwable $e) {
            $resultStreaming = null;
        }

        $report['ai_response_streaming'] = $resultStreaming['answer'] ?? null;
        $report['debug_id_streaming'] = $resultStreaming['debug_id'] ?? null;
        $report['critical_checks']['streaming_works'] = $streamingWorks;

        $debugDataStreaming = [];
        if (!empty($resultStreaming['debug_id'])) {
            $debugLogStreaming = $this->debugLogRepo->findByDebugId($resultStreaming['debug_id']);
            $debugDataStreaming = $debugLogStreaming?->getData() ?? [];
        }
        $report['usage_test_streaming'] = $debugDataStreaming['usage'] ?? [];
        $report['debug_data_streaming'] = $debugDataStreaming;
    }

    private function executeAnalysisStep(SynapsePreset $preset, array &$report): void
    {
        $debugData = $report['debug_data_sync'] ?? [];
        $debugDataStreaming = $report['debug_data_streaming'] ?? [];
        $syncError = $report['sync_error'] ?? null;
        $syncUsage = $report['usage_test'] ?? [];
        $streamingUsage = $report['usage_test_streaming'] ?? [];

        // Prepare JSONs
        $presetConfig = $preset->toArray();
        unset($presetConfig['provider_credentials']);
        $presetConfigJson = json_encode($presetConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $normalizedParamsJson = json_encode($debugData['preset_config'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $rawRequestJson = json_encode($debugData['raw_request_body'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $rawResponse = $debugData['raw_api_response'] ?? $debugData['raw_api_chunks'] ?? [];
        if (empty($rawResponse) && $syncError !== null) {
            $rawResponse = ['error_from_api_client' => $syncError];
        }
        $rawResponseJson = json_encode($rawResponse, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());
        $capsJson = json_encode([
            'thinking_supported' => $caps->thinking,
            'safety_settings_supported' => $caps->safetySettings,
            'top_k_supported' => $caps->topK,
            'function_calling_supported' => $caps->functionCalling,
            'streaming_supported' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $streamingComparisonText = '';
        if (($report['critical_checks']['streaming_enabled'] ?? false) && !empty($debugDataStreaming)) {
            $streamingComparisonText = sprintf(
                "\n\n## 5. Comparaison Synchrone vs Streaming\n" .
                    "**Mode synchrone**: Tokens entrée=%d, sortie=%d\n" .
                    "**Mode streaming**: Tokens entrée=%d, sortie=%d\n",
                $syncUsage['prompt_tokens'] ?? 0,
                $syncUsage['completion_tokens'] ?? 0,
                $streamingUsage['prompt_tokens'] ?? 0,
                $streamingUsage['completion_tokens'] ?? 0
            );
        }

        $analysisPrompt = sprintf(
            "Tu es un agent de validation du système Synapse LLM. Analyse les données suivantes.\n\n" .
                "IMPORTANT: Si la réponse brute contient une erreur de l'API (ex: 400 Bad Request), explique pourquoi.\n" .
                "IMPORTANT: Vérifie les INCOHÉRENCES DE CAPACITÉS.\n" .
                "## 0. Capacités\n```json\n%s\n```\n" .
                "## 1. Preset\n```json\n%s\n```\n" .
                "## 2. Paramètres\n```json\n%s\n```\n" .
                "## 3. Requête\n```json\n%s\n```\n" .
                "## 4. Réponse\n```json\n%s\n```\n" .
                "## 5. Tokens\n- **Sync**: %d+%d+%d=%d (%s)\n- **Stream**: %d+%d+%d=%d (%s)\n%s" .
                "\n\nRetourne un rapport Markdown avec : ### ✅ Points conformes, ### ⚠️ Anomalies détectées, ### Conclusion.",
            $capsJson,
            $presetConfigJson,
            $normalizedParamsJson,
            $rawRequestJson,
            $rawResponseJson,
            $syncUsage['prompt_tokens'] ?? 0,
            $syncUsage['completion_tokens'] ?? 0,
            $syncUsage['thinking_tokens'] ?? 0,
            $syncUsage['total_tokens'] ?? 0,
            (($syncUsage['prompt_tokens'] ?? 0) + ($syncUsage['completion_tokens'] ?? 0) + ($syncUsage['thinking_tokens'] ?? 0)) === ($syncUsage['total_tokens'] ?? 0) ? '✅' : '❌',
            $streamingUsage['prompt_tokens'] ?? 0,
            $streamingUsage['completion_tokens'] ?? 0,
            $streamingUsage['thinking_tokens'] ?? 0,
            $streamingUsage['total_tokens'] ?? 0,
            (($streamingUsage['prompt_tokens'] ?? 0) + ($streamingUsage['completion_tokens'] ?? 0) + ($streamingUsage['thinking_tokens'] ?? 0)) === ($streamingUsage['total_tokens'] ?? 0) ? '✅' : '❌',
            $streamingComparisonText
        );

        $analysisResult = $this->chatService->ask($analysisPrompt, ['stateless' => true, 'debug' => false, 'tools' => []]);

        $report['analysis'] = $analysisResult['answer'] ?? 'Analyse indisponible.';
        $report['all_critical_ok'] = !in_array(false, $report['critical_checks'], true);

        // Cleanup internal data to keep cache light
        unset($report['debug_data_sync'], $report['debug_data_streaming'], $report['sync_error']);
    }
}
