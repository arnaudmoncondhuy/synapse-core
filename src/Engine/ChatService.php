<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestrateur principal des échanges conversationnels avec l'IA.
 *
 * Cette classe coordonne :
 * 1. La construction du contexte (Prompt Builder).
 * 2. La sélection du client LLM actif (LlmClientRegistry).
 * 3. La communication via streaming normalisé.
 * 4. L'exécution dynamique des outils (Function Calling).
 * 5. La boucle de réflexion multi-tours avec l'IA.
 *
 * Les chunks yielded par les clients LLM sont au format Synapse normalisé :
 *   ['text' => string|null, 'thinking' => string|null, 'function_calls' => [...],
 *    'usage' => [...], 'safety_ratings' => [...], 'blocked' => bool, 'blocked_reason' => string|null]
 */
class ChatService
{
    /** @var int Nombre de tours maximum pour la boucle de réflexion multi-tours (function calling) */
    private const MAX_TURNS = 5;

    public function __construct(
        private LlmClientRegistry $llmRegistry,
        private ConfigProviderInterface $configProvider,
        private EventDispatcherInterface $dispatcher,
        private SynapseProfiler $profiler,
        private ?\ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager $conversationManager = null,
        private ?\ArnaudMoncondhuy\SynapseCore\Accounting\SpendingLimitChecker $spendingLimitChecker = null,
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
     * @param callable(string $msg, string $step): void|null $onStatusUpdate Callback appelé à chaque étape (thinking, tool_call, etc.).
     * @param callable(string $token): void|null $onToken callback appelé à chaque token reçu (streaming)
     * @param callable(string $toolName, mixed $result): void|null $onToolExecuted callback appelé après exécution d'un outil
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
        ?callable $onStatusUpdate = null,
        ?callable $onToken = null,
        ?callable $onToolExecuted = null,
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

        if ($onStatusUpdate) {
            $onStatusUpdate('Analyse de la demande...', 'thinking');
        }

        /** @var array{tone?: string, history?: array<int, array<string, mixed>>, stateless?: bool, debug?: bool, preset?: SynapseModelPreset, conversation_id?: string, user_id?: string, estimated_cost_reference?: float, streaming?: bool, reset_conversation?: bool} $askOptions */
        $askOptions = $options;

        // ── DISPATCH GENERATION STARTED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationStartedEvent($message, $askOptions));

        // ── DISPATCH PRE-PROMPT EVENT ──
        // ContextBuilderSubscriber will populate prompt, config, and tool definitions
        $prePromptEvent = $this->dispatcher->dispatch(new SynapsePrePromptEvent($message, $askOptions, [], [], $images));
        /** @var array{contents: array<int, array<string, mixed>>, toolDefinitions?: array<int, array<string, mixed>>} $prompt */
        $prompt = $prePromptEvent->getPrompt();
        /** @var array<string, mixed> $config */
        $config = $prePromptEvent->getConfig();

        // Support preset override
        $presetOverride = $askOptions['preset'] ?? null;
        if ($presetOverride instanceof SynapseModelPreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        try {
            // ── SPENDING LIMIT CHECK ──
            $this->assertSpendingLimit($askOptions, $config);

            // ── RESOLVE RUNTIME CONFIG ──
            $debugMode = $this->resolveDebugMode($askOptions, $config);
            $activeClient = $this->llmRegistry->getClient();
            $streamingEnabled = $this->resolveStreamingEnabled($askOptions, $config);

            // ── MULTI-TURN LOOP ──
            $maxTurns = is_numeric($config['max_turns'] ?? null) ? max(1, (int) $config['max_turns']) : self::MAX_TURNS;
            $loopResult = $this->runMultiTurnLoop(
                $prompt,
                $activeClient,
                $streamingEnabled,
                $maxTurns,
                $onStatusUpdate,
                $onToken,
                $onToolExecuted,
            );

            // ── FINALIZE ──
            return $this->finalizeAndDispatch(
                $loopResult['fullText'],
                $loopResult['usage'],
                $loopResult['safetyRatings'],
                $loopResult['rawData'],
                $config,
                $activeClient,
                $debugMode,
            );
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
     * @param array<string, mixed> $config
     */
    private function assertSpendingLimit(array $askOptions, array $config): void
    {
        if (null === $this->spendingLimitChecker) {
            return;
        }

        $userId = $askOptions['user_id'] ?? null;
        if (!is_string($userId)) {
            return;
        }

        $presetId = is_numeric($config['preset_id'] ?? null) ? (int) $config['preset_id'] : null;
        $agentId = is_numeric($config['agent_id'] ?? null) ? (int) $config['agent_id'] : null;
        $estimatedCostRef = (float) ($askOptions['estimated_cost_reference'] ?? 0.0);

        $this->spendingLimitChecker->assertCanSpend($userId, $presetId, $estimatedCostRef, $agentId);
    }

    /**
     * Résout le mode debug en combinant l'option appelant et la config globale.
     * - Si l'appelant passe explicitement `debug: true`  → activé
     * - Si l'appelant ne précise pas `debug`             → suit debug_mode de la config
     * - Si l'appelant passe explicitement `debug: false` → désactivé même si config active
     *
     * @param array<string, mixed> $askOptions
     * @param array<string, mixed> $config
     */
    private function resolveDebugMode(array $askOptions, array $config): bool
    {
        $callerDebug = $askOptions['debug'] ?? null;

        return true === $callerDebug || (null === $callerDebug && (bool) ($config['debug_mode'] ?? false));
    }

    /**
     * Résout si le streaming est activé (option appelant prioritaire sur la config preset).
     *
     * @param array<string, mixed> $askOptions
     * @param array<string, mixed> $config
     */
    private function resolveStreamingEnabled(array $askOptions, array $config): bool
    {
        if (isset($askOptions['streaming'])) {
            return (bool) $askOptions['streaming'];
        }

        $disabledCaps = is_array($config['disabled_capabilities'] ?? null) ? $config['disabled_capabilities'] : [];

        return ($config['streaming_enabled'] ?? true) && !in_array('streaming', $disabledCaps, true);
    }

    /**
     * Traite l'itérable de chunks reçus du LLM et accumule les données du tour courant.
     *
     * @param iterable<mixed>  $chunks
     * @param callable|null    $onToken
     *
     * @return array{
     *     modelText: string,
     *     modelToolCalls: list<array<string, mixed>>,
     *     usage: array<string, int>,
     *     safetyRatings: array<int, array<string, mixed>>
     * }
     */
    private function processChunks(iterable $chunks, int $turn, ?callable $onToken): array
    {
        $modelText = '';
        $modelToolCalls = [];
        $usage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'thinking_tokens' => 0,
            'total_tokens' => 0,
        ];
        $safetyRatings = [];

        foreach ($chunks as $chunkMixed) {
            if (!is_array($chunkMixed)) {
                continue;
            }
            /** @var array<string, mixed> $chunk */
            $chunk = $chunkMixed;

            // Dispatch ChunkReceivedEvent (for debug logging and streaming)
            $this->dispatcher->dispatch(new SynapseChunkReceivedEvent($chunk, $turn));

            // Accumulate text
            if (!empty($chunk['text']) && is_string($chunk['text'])) {
                $chunkText = (string) $chunk['text'];
                $modelText .= $chunkText;
                if ($onToken) {
                    $onToken($chunkText);
                }
            }

            // Accumulate usage
            if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
                $u = $chunk['usage'];
                $usage['prompt_tokens'] += is_numeric($u['prompt_tokens'] ?? null) ? (int) $u['prompt_tokens'] : 0;
                $usage['completion_tokens'] += is_numeric($u['completion_tokens'] ?? null) ? (int) $u['completion_tokens'] : 0;
                $usage['thinking_tokens'] += is_numeric($u['thinking_tokens'] ?? null) ? (int) $u['thinking_tokens'] : 0;
                $usage['total_tokens'] += is_numeric($u['total_tokens'] ?? null) ? (int) $u['total_tokens'] : 0;
            }

            if (!empty($chunk['safety_ratings']) && is_array($chunk['safety_ratings'])) {
                $safetyRatings = $chunk['safety_ratings'];
            }

            // Handle blocked responses — si bloqué, on n'exécute pas les function_calls du même chunk
            if ((bool) ($chunk['blocked'] ?? false)) {
                $reason = is_string($chunk['blocked_reason'] ?? null) ? (string) $chunk['blocked_reason'] : 'contenu bloqué par les filtres de sécurité';
                $blockedMsg = "⚠️ Ma réponse a été bloquée ({$reason}). Veuillez reformuler votre demande.";
                $modelText .= $blockedMsg;
                if ($onToken) {
                    /** @var callable(string): void $tokenCallback */
                    $tokenCallback = $onToken;
                    $tokenCallback($blockedMsg);
                }
                continue; // Skip function_calls collection for this chunk
            }

            // Collect function calls in OpenAI format
            if (!empty($chunk['function_calls']) && is_array($chunk['function_calls'])) {
                foreach ($chunk['function_calls'] as $fc) {
                    if (!is_array($fc)) {
                        continue;
                    }
                    $nameMixed = $fc['name'] ?? null;
                    if (null === $nameMixed && isset($fc['function']) && is_array($fc['function'])) {
                        $nameMixed = $fc['function']['name'] ?? null;
                    }
                    $name = is_string($nameMixed) ? $nameMixed : '';
                    if ('' === $name) {
                        continue;
                    }

                    $rawArgs = $fc['args'] ?? [];
                    if (empty($rawArgs) && isset($fc['function']) && is_array($fc['function'])) {
                        $rawArgs = $fc['function']['arguments'] ?? [];
                    }
                    $argsJson = is_string($rawArgs) ? $rawArgs : json_encode($rawArgs, JSON_UNESCAPED_UNICODE);
                    $modelToolCalls[] = [
                        'id' => is_string($fc['id'] ?? null) ? (string) $fc['id'] : 'call_'.bin2hex(random_bytes(6)),
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => $argsJson,
                        ],
                    ];
                }
            }
        }

        return [
            'modelText' => $modelText,
            'modelToolCalls' => $modelToolCalls,
            'usage' => $usage,
            'safetyRatings' => $safetyRatings,
        ];
    }

    /**
     * Exécute les tool calls demandés par le LLM et injecte les résultats dans le prompt.
     *
     * Fix Bug 1 : un tool result null génère quand même un message role:tool (contenu vide),
     * ce qui évite que le LLM re-demande indéfiniment le même outil jusqu'à MAX_TURNS.
     *
     * @param array<string, mixed>         $prompt         Modifié par référence : les résultats sont ajoutés à contents
     * @param list<array<string, mixed>>   $modelToolCalls Tool calls en format OpenAI
     * @param callable|null                $onStatusUpdate
     * @param callable|null                $onToolExecuted
     */
    private function executeToolCalls(
        array &$prompt,
        array $modelToolCalls,
        int $turn,
        ?callable $onStatusUpdate,
        ?callable $onToolExecuted,
    ): void {
        // Normalise modelToolCalls to event format
        $eventToolCalls = array_map(function ($tc) {
            $decodedArgs = json_decode(is_string($tc['function']['arguments']) ? $tc['function']['arguments'] : '', true);

            return [
                'id' => (string) $tc['id'],
                'name' => (string) $tc['function']['name'],
                'args' => is_array($decodedArgs) ? $decodedArgs : [],
            ];
        }, $modelToolCalls);

        $toolEvent = $this->dispatcher->dispatch(new SynapseToolCallRequestedEvent($eventToolCalls));
        $toolResults = $toolEvent->getResults();

        foreach ($modelToolCalls as $tc) {
            $toolName = (string) $tc['function']['name'];
            $this->profiler->start('Tool', 'Tool Execution: '.$toolName, "Exécution locale d'une fonction (outil) demandée par le LLM.");

            $toolResult = $toolResults[$toolName] ?? null;

            if ($onStatusUpdate) {
                $onStatusUpdate("Exécution de l'outil: {$toolName}...", 'tool:'.$toolName);
            }

            // Toujours ajouter le message role:tool même si le résultat est null,
            // pour éviter que le LLM boucle en re-demandant le même outil (Bug 1 fix).
            $prompt['contents'][] = [
                'role' => 'tool',
                'tool_call_id' => (string) $tc['id'],
                'content' => null !== $toolResult
                    ? (is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE))
                    : '',
            ];

            if (null !== $toolResult) {
                $this->dispatcher->dispatch(new SynapseToolCallCompletedEvent($toolName, $toolResult, $tc));
                if ($onToolExecuted) {
                    $onToolExecuted($toolName, $toolResult);
                }
            }

            $this->profiler->stop('Tool', 'Tool Execution: '.$toolName, $turn);
        }
    }

    /**
     * Boucle multi-tours : appelle le LLM, traite les chunks, exécute les outils si nécessaire.
     *
     * Fix Bug 2 : les données raw de TOUS les tours sont accumulées (pas seulement le tour 0).
     *
     * @param array<string, mixed>  $prompt          Modifié par référence (historique ajouté à chaque tour)
     * @param callable|null         $onStatusUpdate
     * @param callable|null         $onToken
     * @param callable|null         $onToolExecuted
     *
     * @return array{
     *     fullText: string,
     *     usage: array<string, int>,
     *     safetyRatings: array<int, array<string, mixed>>,
     *     rawData: array<string, mixed>
     * }
     */
    private function runMultiTurnLoop(
        array &$prompt,
        LlmClientInterface $activeClient,
        bool $streamingEnabled,
        int $maxTurns,
        ?callable $onStatusUpdate,
        ?callable $onToken,
        ?callable $onToolExecuted,
    ): array {
        $fullTextAccumulator = '';
        $cumulativeUsage = [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'thinking_tokens' => 0,
            'total_tokens' => 0,
        ];
        $finalSafetyRatings = [];
        $allTurnsRawData = [];

        for ($turn = 0; $turn < $maxTurns; ++$turn) {
            if ($onStatusUpdate && $turn > 0) {
                $onStatusUpdate('Réflexion supplémentaire...', 'thinking');
            }

            $debugOut = [];

            // ── LLM CALL (Streaming or Sync) ──
            $this->profiler->start('LLM', 'LLM Network Call & Streaming', "Durée totale de l'échange réseau avec l'API du fournisseur (attente + réception des chunks).");
            /** @var array<int, array<string, mixed>> $contents */
            $contents = $prompt['contents'];
            /** @var array<int, array<string, mixed>> $toolDefinitions */
            $toolDefinitions = $prompt['toolDefinitions'] ?? [];

            if ($streamingEnabled) {
                $chunks = $activeClient->streamGenerateContent($contents, $toolDefinitions, null, $debugOut);
            } else {
                $response = $activeClient->generateContent($contents, $toolDefinitions, null, [], $debugOut);
                $chunks = [$response];
            }

            // ── PROCESS CHUNKS ──
            $chunkResult = $this->processChunks($chunks, $turn, $onToken);

            $modelText = $chunkResult['modelText'];
            $modelToolCalls = $chunkResult['modelToolCalls'];

            // ── RESOLVE TIMING (after generator fully consumed) ──
            $this->profiler->stop('LLM', 'LLM Network Call & Streaming', $turn);

            // ── ACCUMULATE RAW DATA (Bug 2 fix: tous les tours) ──
            if (!empty($debugOut)) {
                if (0 === $turn && !empty($debugOut['raw_request_body'])) {
                    $allTurnsRawData['raw_request_body'] = $debugOut['raw_request_body'];
                }
                if (!empty($debugOut['raw_api_chunks']) && is_array($debugOut['raw_api_chunks'])) {
                    $allTurnsRawData['raw_api_chunks'] = array_merge(
                        $allTurnsRawData['raw_api_chunks'] ?? [],
                        $debugOut['raw_api_chunks']
                    );
                }
                if (!empty($debugOut['raw_api_response'])) {
                    $allTurnsRawData['raw_api_response'] = $debugOut['raw_api_response'];
                }
            }

            // ── ACCUMULATE USAGE AND SAFETY ──
            $u = $chunkResult['usage'];
            $cumulativeUsage['prompt_tokens'] += $u['prompt_tokens'];
            $cumulativeUsage['completion_tokens'] += $u['completion_tokens'];
            $cumulativeUsage['thinking_tokens'] += $u['thinking_tokens'];
            $cumulativeUsage['total_tokens'] += $u['total_tokens'];
            if (!empty($chunkResult['safetyRatings'])) {
                $finalSafetyRatings = $chunkResult['safetyRatings'];
            }

            $fullTextAccumulator .= $modelText;

            // ── ADD MODEL RESPONSE TO HISTORY (OpenAI format) ──
            if ('' !== $modelText || !empty($modelToolCalls)) {
                $entry = ['role' => 'assistant', 'content' => $modelText ?: null];
                if (!empty($modelToolCalls)) {
                    $entry['tool_calls'] = $modelToolCalls;
                }
                $prompt['contents'][] = $entry;
            }

            // ── PROCESS TOOL CALLS ──
            if (!empty($modelToolCalls)) {
                $this->executeToolCalls($prompt, $modelToolCalls, $turn, $onStatusUpdate, $onToolExecuted);
                // Continuer la boucle : le LLM reçoit les résultats et peut enchaîner
                continue;
            }

            // Aucun tool call → fin de l'échange
            break;
        }

        return [
            'fullText' => $fullTextAccumulator,
            'usage' => $cumulativeUsage,
            'safetyRatings' => $finalSafetyRatings,
            'rawData' => $allTurnsRawData,
        ];
    }

    /**
     * Finalise l'échange : calcule les totaux, dispatche les events de complétion, retourne le résultat.
     *
     * @param array<string, int>              $usage
     * @param array<int, array<string, mixed>> $safetyRatings
     * @param array<string, mixed>             $rawData
     * @param array<string, mixed>             $config
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
        array $usage,
        array $safetyRatings,
        array $rawData,
        array $config,
        LlmClientInterface $activeClient,
        bool $debugMode,
    ): array {
        // Ensure total_tokens is set (sum if not provided by API)
        if (0 === $usage['total_tokens']) {
            $usage['total_tokens'] = $usage['prompt_tokens'] + $usage['completion_tokens'] + $usage['thinking_tokens'];
        }

        $debugId = null;

        if ($debugMode) {
            $debugId = uniqid('dbg_', true);
            $timings = $this->profiler->getTimings();

            $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                $debugId,
                is_string($config['model'] ?? null) ? (string) $config['model'] : 'unknown',
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
            'usage' => $usage,
            'safety' => $safetyRatings,
            'model' => is_string($config['model'] ?? null) ? (string) $config['model'] : (is_string($config['provider'] ?? null) ? (string) $config['provider'] : 'unknown'),
            'preset_id' => is_numeric($config['preset_id'] ?? null) ? (int) $config['preset_id'] : null,
            'agent_id' => is_numeric($config['agent_id'] ?? null) ? (int) $config['agent_id'] : null,
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
