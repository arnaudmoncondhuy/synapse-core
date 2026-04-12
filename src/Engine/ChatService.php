<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseStatusChangedEvent;
use ArnaudMoncondhuy\SynapseCore\Service\ConversationContextHolder;
use ArnaudMoncondhuy\SynapseCore\Service\ImageGenerationService;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\ResponseSchemaNotSupportedException;
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
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly ResponseFormatNormalizer $responseFormatNormalizer,
        private readonly ?ImageGenerationService $imageGenerationService = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager $conversationManager = null,
        private readonly ?\ArnaudMoncondhuy\SynapseCore\Accounting\SpendingLimitChecker $spendingLimitChecker = null,
        private readonly ?TokenAccountingService $tokenAccountingService = null,
        private readonly ?ConversationContextHolder $conversationContextHolder = null,
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
     *     agent?: string,
     *     response_format?: array<string, mixed>,
     *     module?: string,
     *     action?: string
     * } $options Options contrôlant le comportement de l'échange
     * @param list<array{mime_type: string, data: string}> $attachments Fichiers attachés au message (vision, PDF, etc.)
     *
     * @return array{
     *     answer: string,
     *     debug_id: ?string,
     *     call_id: ?string,
     *     usage: array<string, int>,
     *     safety: array<int, array<string, mixed>>,
     *     model: string,
     *     preset_id: ?int,
     *     agent_id: ?int,
     *     generated_attachments: list<array{mime_type: string, data: string}>,
     *     structured_output?: array<string, mixed>
     * } Résultat normalisé de l'échange
     */
    public function ask(
        string $message,
        array $options = [],
        array $attachments = [],
    ): array {
        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return [
                'answer' => '',
                'debug_id' => null,
                'call_id' => null,
                'usage' => [],
                'safety' => [],
                'model' => 'unknown',
                'preset_id' => null,
                'agent_id' => null,
                'generated_attachments' => [],
            ];
        }

        $this->dispatcher->dispatch(new SynapseStatusChangedEvent('Analyse de la demande...', 'thinking'));

        /** @var array{tone?: string, history?: array<int, array<string, mixed>>, stateless?: bool, debug?: bool, preset?: SynapseModelPreset, conversation_id?: string, user_id?: string, estimated_cost_reference?: float, streaming?: bool, reset_conversation?: bool, module?: string, action?: string} $askOptions */
        $askOptions = $options;

        // Module/action logiques (pour dénormalisation SynapseDebugLog et affichage liste debug).
        // Alignés sur TokenAccountingService::logUsage($module, $action, ...) pour utiliser le
        // même vocabulaire dans les deux tables. `null` si l'appelant ne précise rien.
        $module = isset($askOptions['module']) && is_string($askOptions['module']) ? $askOptions['module'] : null;
        $action = isset($askOptions['action']) && is_string($askOptions['action']) ? $askOptions['action'] : null;

        // IDs utilisés par le token accounting (ChatService est devenu le point unique de logUsage).
        $userId = isset($askOptions['user_id']) && is_string($askOptions['user_id']) ? $askOptions['user_id'] : null;
        $conversationId = isset($askOptions['conversation_id']) && is_string($askOptions['conversation_id']) ? $askOptions['conversation_id'] : null;

        // Propager le contexte conversation pour que les tools (ex: CodeExecuteTool) puissent accéder aux fichiers.
        // Les attachments sont stockés ici car ils ne sont persistés en base qu'APRÈS ask().
        if (null !== $this->conversationContextHolder) {
            if (null !== $conversationId && '' !== $conversationId) {
                $this->conversationContextHolder->set($conversationId);
            }
            if (!empty($attachments)) {
                $this->conversationContextHolder->setAttachments($attachments);
            }
        }

        // ── AGENT CONTEXT PROPAGATION ──
        // Si l'appel vient d'un agent (via AgentResolver + call()), un AgentContext est
        // passé via $options['context']. Il est propagé dans les events de complétion pour
        // permettre au DebugLogSubscriber d'enrichir le SynapseDebugLog avec les champs
        // de traçabilité (parent_run_id, agent_run_id, depth, origin). Absent = appel racine
        // non-agent (chat interactif classique).
        $agentContext = $options['context'] ?? null;
        if (!$agentContext instanceof AgentContext) {
            $agentContext = null;
        }

        // ── DISPATCH GENERATION STARTED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationStartedEvent($message, $askOptions));

        // ── PRESET OVERRIDE (avant le pipeline pour que la config soit cohérente partout) ──
        $presetOverride = $askOptions['preset'] ?? null;
        if ($presetOverride instanceof SynapseModelPreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        // ── PROMPT PIPELINE (BUILD → ENRICH → OPTIMIZE → FINALIZE → CAPTURE) ──
        $pipelineResult = $this->promptPipeline->build($message, $askOptions, $attachments);
        /** @var array{contents: array<int, array<string, mixed>>, toolDefinitions?: array<int, array<string, mixed>>} $prompt */
        $prompt = $pipelineResult['prompt'];
        $config = $pipelineResult['config'] ?? $this->configProvider->getConfig();

        // ── PROPAGATE PIPELINE CONFIG AS OVERRIDE ──
        // Le pipeline peut changer la config (ex: agent avec un preset spécifique).
        // Il faut propager cette config au configProvider pour que les clients LLM
        // les clients LLM l'utilisent via applyDynamicConfig().
        $this->configProvider->setOverride($config);

        // ── IMAGE-ONLY MODEL ROUTING ──
        // Si le modèle ne supporte que la génération d'image (pas de text), on route vers ImageGenerationService
        $modelId = $config->model ?: '';
        if ('' !== $modelId
            && !$this->capabilityRegistry->supports($modelId, 'text_generation')
            && $this->capabilityRegistry->supports($modelId, 'image_generation')
            && null !== $this->imageGenerationService
        ) {
            return $this->handleImageOnlyModel(
                $message,
                $config,
                $this->resolveDebugMode($askOptions, $config),
                $module,
                $action,
                $userId,
                $conversationId,
            );
        }

        $debugMode = false;
        $activeClient = null;

        try {
            // ── SPENDING LIMIT CHECK ──
            $this->assertSpendingLimit($askOptions, $config);

            // ── RESOLVE RUNTIME CONFIG ──
            $debugMode = $this->resolveDebugMode($askOptions, $config);
            $activeClient = $this->llmRegistry->getClient();

            // ── STRUCTURED OUTPUT (response_format) ──
            // Valide, vérifie la capability du modèle, puis thread jusqu'aux clients LLM via $llmOptions.
            // Doit se faire AVANT resolveStreamingEnabled() pour qu'on puisse forcer streaming=off
            // (streaming + JSON mode = complique le merge sans bénéfice UX).
            $llmOptions = [];
            if (isset($askOptions['response_format']) && \is_array($askOptions['response_format'])) {
                $modelForCheck = $config->model ?: '';
                if ('' === $modelForCheck || !$this->capabilityRegistry->supports($modelForCheck, 'response_schema')) {
                    throw ResponseSchemaNotSupportedException::forModel($modelForCheck);
                }
                $llmOptions['response_format'] = $this->responseFormatNormalizer->normalize($askOptions['response_format']);
            }

            $streamingEnabled = $this->resolveStreamingEnabled($askOptions, $config);
            // Force non-streaming si un response_format est demandé : streamer du JSON n'apporte
            // rien en UX et complique le merge des chunks. Override silencieux.
            if (isset($llmOptions['response_format']) && $streamingEnabled) {
                $streamingEnabled = false;
            }

            // ── MULTI-TURN LOOP ──
            $maxTurns = max(1, $config->maxTurns);
            $loopResult = $this->multiTurnExecutor->execute(
                $prompt,
                $activeClient,
                $streamingEnabled,
                $maxTurns,
                $llmOptions,
            );

            // ── FINALIZE ──
            // Merger les artefacts générés par les tools (code_execute, etc.) avec ceux du LLM
            $toolArtifacts = $this->conversationContextHolder?->getGeneratedArtifacts() ?? [];
            $allGeneratedAttachments = array_merge($loopResult->generatedAttachments, $toolArtifacts);

            return $this->finalizeAndDispatch(
                $loopResult->fullText,
                $loopResult->usage,
                $loopResult->safetyRatings,
                $loopResult->rawData,
                $config,
                $activeClient,
                $debugMode,
                $allGeneratedAttachments,
                $agentContext,
                $loopResult->structuredData,
                $module,
                $action,
                $userId,
                $conversationId,
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
                    $agentContext,
                    $module,
                    $action,
                ));
            }
            throw $e;
        } finally {
            // Garantit la réinitialisation de l'override même en cas d'exception
            // Critique en mode FrankenPHP worker : les services sont partagés entre requêtes
            $this->configProvider->setOverride(null);
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
        // Force non-streaming si le modèle ne le supporte pas
        if ('' !== $config->model && !$this->capabilityRegistry->supports($config->model, 'streaming')) {
            return false;
        }

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
     * @param list<array{mime_type: string, data: string}> $generatedAttachments
     * @param array<string, mixed>|null $structuredData Objet JSON parsé quand `response_format` est actif
     *
     * @return array{
     *     answer: string,
     *     debug_id: ?string,
     *     call_id: ?string,
     *     usage: array<string, int>,
     *     safety: array<int, array<string, mixed>>,
     *     model: string,
     *     preset_id: ?int,
     *     agent_id: ?int,
     *     generated_attachments: list<array{mime_type: string, data: string}>,
     *     structured_output?: array<string, mixed>
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
        array $generatedAttachments = [],
        ?AgentContext $agentContext = null,
        ?array $structuredData = null,
        ?string $module = null,
        ?string $action = null,
        ?string $userId = null,
        ?string $conversationId = null,
    ): array {
        // ── TOKEN ACCOUNTING (single source of truth) ──
        // Un appel LLM = une ligne SynapseLlmCall. Logguer ici AVANT le debug event
        // pour que le call_id soit disponible dans le payload debug (lien direct
        // debug_log → llm_call, évite de dupliquer tokens/coûts).
        // Voir feedback_token_cost_single_source : le calcul des coûts ne doit avoir qu'un
        // seul point d'entrée pour faciliter les corrections de bugs.
        $callId = $this->recordTokenUsage(
            $module,
            $action,
            $config,
            $usage,
            $userId,
            $conversationId,
        );

        $debugId = null;

        if ($debugMode) {
            $debugId = uniqid('dbg_', true);
            $timings = $this->profiler->getTimings();

            // Injecter le call_id dans rawData pour lier debug_log → llm_call
            $rawData['call_id'] = $callId;

            $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                $debugId,
                $config->model ?: 'unknown',
                $activeClient->getProviderName(),
                $usage,
                $safetyRatings,
                $debugMode,
                $rawData,
                $timings,
                $agentContext,
                $module,
                $action,
            ));
        }

        // Purge the current timers for next call
        $this->profiler->reset();

        $this->dispatcher->dispatch(new SynapseGenerationCompletedEvent($fullText, $usage, $debugId));

        $result = [
            'answer' => $fullText,
            'debug_id' => $debugId,
            'call_id' => $callId,
            'usage' => $usage->toArray(),
            'safety' => $safetyRatings,
            'model' => $config->model ?: $config->provider ?: 'unknown',
            'preset_id' => $config->presetId,
            'agent_id' => $config->agentId,
            'generated_attachments' => $generatedAttachments,
        ];

        if (null !== $structuredData) {
            $result['structured_output'] = $structuredData;
        }

        // Nettoyer le contexte conversation (request-scoped)
        $this->conversationContextHolder?->clear();

        return $result;
    }

    /**
     * Enregistre l'appel LLM dans `synapse_llm_call` via {@see TokenAccountingService::logUsage()}.
     *
     * Point unique d'entrée pour le calcul des tokens/coûts. Retourne `null` si :
     * - `TokenAccountingService` n'est pas injecté (tests unitaires sans accounting)
     * - `module` ou `action` sont absents (appelant qui n'a pas encore migré)
     *
     * Voir `feedback_token_cost_single_source` : aucun autre code ne doit appeler
     * directement `logUsage()` en parallèle de `ChatService::ask()`.
     */
    private function recordTokenUsage(
        ?string $module,
        ?string $action,
        SynapseRuntimeConfig $config,
        TokenUsage $usage,
        ?string $userId,
        ?string $conversationId,
    ): ?string {
        if (null === $this->tokenAccountingService) {
            return null;
        }
        if (null === $module || null === $action) {
            return null;
        }

        try {
            $llmCall = $this->tokenAccountingService->logUsage(
                $module,
                $action,
                $config->model ?: 'unknown',
                $usage,
                $userId,
                $conversationId,
                $config->presetId,
                $config->agentId,
            );

            return $llmCall->getCallId();
        } catch (\Throwable $e) {
            // Fail-safe : ne jamais casser un échange LLM pour un problème de comptabilité.
            // L'utilisateur doit recevoir sa réponse même si l'accounting échoue.
            return null;
        }
    }

    /**
     * Route un modèle image-only vers ImageGenerationService.
     *
     * @return array{answer: string, debug_id: ?string, call_id: ?string, usage: array<string, int>, safety: array<int, array<string, mixed>>, model: string, preset_id: ?int, agent_id: ?int, generated_attachments: list<array{mime_type: string, data: string}>, type: string}
     */
    private function handleImageOnlyModel(
        string $message,
        SynapseRuntimeConfig $config,
        bool $debugMode,
        ?string $module = null,
        ?string $action = null,
        ?string $userId = null,
        ?string $conversationId = null,
    ): array {
        $this->dispatcher->dispatch(new SynapseStatusChangedEvent('Génération d\'image en cours...', 'thinking'));

        $caps = $this->capabilityRegistry->getCapabilities($config->model);
        if (null === $this->imageGenerationService) {
            throw new \LogicException('ImageGenerationService is required for image-only models.');
        }

        $images = $this->imageGenerationService->generate($message, $caps->provider, [
            'model' => $config->model,
        ]);

        $generatedAttachments = array_map(fn ($img) => [
            'mime_type' => $img->mimeType,
            'data' => $img->data,
        ], $images);

        // Bascule automatique de l'action : un appelant qui passe `chat_turn` (ou rien) et
        // tombe sur un modèle image-only doit voir son appel loggué sous `image_generation`.
        // Continuité avec l'ancien comportement de ChatApiController. Les actions explicites
        // non-chat (ex: `title_generation`) sont respectées telles quelles — elles ne
        // devraient jamais tomber ici, mais si c'est le cas on préserve l'intention appelante.
        $effectiveAction = (null === $action || 'chat_turn' === $action) ? 'image_generation' : $action;

        $debugId = null;
        if ($debugMode) {
            $debugId = uniqid('dbg_img_', true);
            $timings = $this->profiler->getTimings();
            $this->profiler->reset();

            $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                $debugId,
                $config->model ?: 'unknown',
                $caps->provider,
                new TokenUsage(),
                [],
                $debugMode,
                [
                    'type' => 'image_generation',
                    'prompt' => $message,
                    'images_count' => \count($generatedAttachments),
                    'generated_attachments' => $generatedAttachments,
                ],
                $timings,
                null,
                $module,
                $effectiveAction,
            ));
        }

        $this->dispatcher->dispatch(new SynapseGenerationCompletedEvent(
            '',
            new TokenUsage(),
            $debugId,
        ));

        // ── TOKEN ACCOUNTING (single source of truth) ──
        // TokenUsage::empty() car les providers image-only ne renvoient pas de tokens texte.
        // Le tracking sert à compter les requêtes et leur coût (via pricing_output_image).
        $callId = $this->recordTokenUsage(
            $module,
            $effectiveAction,
            $config,
            TokenUsage::empty(),
            $userId,
            $conversationId,
        );

        return [
            'answer' => '',
            'debug_id' => $debugId,
            'call_id' => $callId,
            'usage' => [],
            'safety' => [],
            'model' => $config->model,
            'preset_id' => $config->presetId,
            'agent_id' => $config->agentId,
            'generated_attachments' => $generatedAttachments,
            'type' => 'image_generation',
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
