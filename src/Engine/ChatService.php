<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseStatusChangedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrateur principal des échanges conversationnels avec l'IA.
 *
 * Cette classe coordonne :
 * 1. La construction du contexte (via PromptPipeline : BUILD → ENRICH → OPTIMIZE → FINALIZE → CAPTURE).
 * 2. La sélection du client LLM actif (LlmClientRegistry).
 * 3. La boucle multi-tours et le streaming (MultiTurnExecutor).
 * 4. La finalisation et le dispatch des events de complétion.
 */
class ChatService
{
    public function __construct(
        private readonly LlmClientRegistry $llmRegistry,
        private readonly ConfigProviderInterface $configProvider,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SynapseProfiler $profiler,
        private readonly MultiTurnExecutor $multiTurnExecutor,
        private readonly PromptPipeline $promptPipeline,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager $conversationManager = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Accounting\SpendingLimitChecker $spendingLimitChecker = null,
    ) {
    }

    /**
     * Point d'entrée principal pour envoyer un message à l'IA.
     *
     * Cette méthode gère l'orchestration complète : recherche du contexte, appel du client LLM,
     * exécution des outils (si nécessaire) et persistance des messages.
     *
     * @param string $message le texte envoyé par l'utilisateur
     * @param array{
     *     tone?: string,
     *     history?: array<int, array<string, mixed>>,
     *     stateless?: bool,
     *     debug?: bool,
     *     preset?: SynapseModelPreset,
     *     conversation_id?: string,
     *     user_id?: string,
     *     estimated_cost_reference?: float,
     *     streaming?: bool,
     *     reset_conversation?: bool,
     *     agent?: string
     * } $options Options contrôlant le comportement de l'échange
     * @param list<array{mime_type: string, data: string}> $images Images attachées au message (vision)
     *
     * @return array{
     *     answer: string,
     *     debug_id: ?string,
     *     usage: array<string, int>,
     *     safety: array<int, array<string, mixed>>,
     *     model: string,
     *     preset_id: ?int,
     *     agent_id: ?int
     * } Résultat normalisé de l'échange
     */
    public function ask(
        string $message,
        array $options = [],
        array $images = [],
    ): array {
        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return [
                'answer' => '',
                'debug_id' => null,
                'usage' => [],
                'safety' => [],
                'model' => 'unknown',
                'preset_id' => null,
                'agent_id' => null,
            ];
        }

        $this->dispatcher->dispatch(new SynapseStatusChangedEvent('Analyse de la demande...', 'thinking'));

        /** @var array{tone?: string, history?: array<int, array<string, mixed>>, stateless?: bool, debug?: bool, preset?: SynapseModelPreset, conversation_id?: string, user_id?: string, estimated_cost_reference?: float, streaming?: bool, reset_conversation?: bool} $askOptions */
        $askOptions = $options;

        // ── DISPATCH GENERATION STARTED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationStartedEvent($message, $askOptions));

        // ── PROMPT PIPELINE (BUILD → ENRICH → OPTIMIZE → FINALIZE → CAPTURE) ──
        $pipelineResult = $this->promptPipeline->build($message, $askOptions, $images);
        /** @var array{contents: array<int, array<string, mixed>>, toolDefinitions?: array<int, array<string, mixed>>} $prompt */
        $prompt = $pipelineResult['prompt'];
        $config = $pipelineResult['config'] ?? $this->configProvider->getConfig();

        // Support preset override
        $presetOverride = $askOptions['preset'] ?? null;
        if ($presetOverride instanceof SynapseModelPreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        $debugMode = false;
        $activeClient = null;

        try {
            // ── SPENDING LIMIT CHECK ──
            $this->assertSpendingLimit($askOptions, $config);

            // ── RESOLVE RUNTIME CONFIG ──
            $debugMode = $this->resolveDebugMode($askOptions, $config);
            $activeClient = $this->llmRegistry->getClient();
            $streamingEnabled = $this->resolveStreamingEnabled($askOptions, $config);

            // ── MULTI-TURN LOOP ──
            $maxTurns = max(1, $config->maxTurns);
            $loopResult = $this->multiTurnExecutor->execute(
                $prompt,
                $activeClient,
                $streamingEnabled,
                $maxTurns,
            );

            // ── FINALIZE ──
            return $this->finalizeAndDispatch(
                $loopResult->fullText,
                $loopResult->usage,
                $loopResult->safetyRatings,
                $loopResult->rawData,
                $config,
                $activeClient,
                $debugMode,
            );
        } catch (\Throwable $e) {
            // Save debug log even on failure so errors can be investigated
            if ($debugMode && null !== $activeClient) {
                $debugId = uniqid('dbg_err_', true);
                $timings = $this->profiler->getTimings();
                $this->profiler->reset();
                $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                    $debugId,
                    $config->model ?: 'unknown',
                    $activeClient->getProviderName(),
                    new TokenUsage(),
                    [],
                    $debugMode,
                    ['error' => $e->getMessage(), 'error_class' => $e::class, 'error_file' => basename($e->getFile()).':'.$e->getLine()],
                    $timings,
                ));
            }
            throw $e;
        } finally {
            // Garantit la réinitialisation de l'override même en cas d'exception
            // Critique en mode FrankenPHP worker : les services sont partagés entre requêtes
            if (null !== $presetOverride) {
                $this->configProvider->setOverride(null);
            }
        }
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Vérifie les plafonds de dépense avant tout appel LLM.
     *
     * @param array<string, mixed> $askOptions
     */
    private function assertSpendingLimit(array $askOptions, SynapseRuntimeConfig $config): void
    {
        if (null === $this->spendingLimitChecker) {
            return;
        }

        $userId = $askOptions['user_id'] ?? null;
        if (!is_string($userId)) {
            return;
        }

        $estimatedCostRef = (float) ($askOptions['estimated_cost_reference'] ?? 0.0);
        $this->spendingLimitChecker->assertCanSpend($userId, $config->presetId, $estimatedCostRef, $config->agentId);
    }

    /**
     * Résout le mode debug en combinant l'option appelant et la config globale.
     * - Si l'appelant passe explicitement `debug: true`  → activé
     * - Si l'appelant ne précise pas `debug`             → suit debug_mode de la config
     * - Si l'appelant passe explicitement `debug: false` → désactivé même si config active.
     *
     * @param array<string, mixed> $askOptions
     */
    private function resolveDebugMode(array $askOptions, SynapseRuntimeConfig $config): bool
    {
        $callerDebug = $askOptions['debug'] ?? null;

        return true === $callerDebug || (null === $callerDebug && $config->debugMode);
    }

    /**
     * Résout si le streaming est activé (option appelant prioritaire sur la config preset).
     *
     * @param array<string, mixed> $askOptions
     */
    private function resolveStreamingEnabled(array $askOptions, SynapseRuntimeConfig $config): bool
    {
        if (isset($askOptions['streaming'])) {
            return (bool) $askOptions['streaming'];
        }

        return $config->isStreamingEffective();
    }

    /**
     * Finalise l'échange : calcule les totaux, dispatche les events de complétion, retourne le résultat.
     *
     * @param array<int, array<string, mixed>> $safetyRatings
     * @param array<string, mixed> $rawData
     *
     * @return array{
     *     answer: string,
     *     debug_id: ?string,
     *     usage: array<string, int>,
     *     safety: array<int, array<string, mixed>>,
     *     model: string,
     *     preset_id: ?int,
     *     agent_id: ?int
     * }
     */
    private function finalizeAndDispatch(
        string $fullText,
        TokenUsage $usage,
        array $safetyRatings,
        array $rawData,
        SynapseRuntimeConfig $config,
        LlmClientInterface $activeClient,
        bool $debugMode,
    ): array {
        $debugId = null;

        if ($debugMode) {
            $debugId = uniqid('dbg_', true);
            $timings = $this->profiler->getTimings();

            $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                $debugId,
                $config->model ?: 'unknown',
                $activeClient->getProviderName(),
                $usage,
                $safetyRatings,
                $debugMode,
                $rawData,
                $timings
            ));
        }

        // Purge the current timers for next call
        $this->profiler->reset();

        $this->dispatcher->dispatch(new SynapseGenerationCompletedEvent($fullText, $usage, $debugId));

        return [
            'answer' => $fullText,
            'debug_id' => $debugId,
            'usage' => $usage->toArray(),
            'safety' => $safetyRatings,
            'model' => $config->model ?: $config->provider ?: 'unknown',
            'preset_id' => $config->presetId,
            'agent_id' => $config->agentId,
        ];
    }

    // =========================================================================
    // PUBLIC CONVERSATION HELPERS
    // =========================================================================

    /**
     * Réinitialise l'historique de conversation actuel.
     * Supprime la conversation en base de données si elle existe.
     */
    public function resetConversation(): void
    {
        if ($this->conversationManager) {
            $conversation = $this->conversationManager->getCurrentConversation();
            if ($conversation) {
                $this->conversationManager->deleteConversation($conversation);
                $this->conversationManager->setCurrentConversation(null);
            }
        }
    }

    /**
     * Retourne l'historique complet formaté pour l'API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConversationHistory(): array
    {
        if (!$this->conversationManager) {
            return [];
        }

        $conversation = $this->conversationManager->getCurrentConversation();
        if (!$conversation) {
            return [];
        }

        return $this->conversationManager->getHistoryArray($conversation);
    }
}
