<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseGenerationStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
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
    ) {}

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
     *     preset?: SynapsePreset,
     *     conversation_id?: string,
     *     user_id?: string,
     *     estimated_cost_reference?: float,
     *     streaming?: bool,
     *     reset_conversation?: bool
     * } $options Options contrôlant le comportement de l'échange
     * @param callable(string $msg, string $step): void|null       $onStatusUpdate Callback appelé à chaque étape (thinking, tool_call, etc.).
     * @param callable(string $token): void|null                   $onToken        callback appelé à chaque token reçu (streaming)
     * @param callable(string $toolName, mixed $result): void|null $onToolExecuted callback appelé après exécution d'un outil
     *
     * @return array{
     *     answer: string,
     *     debug_id: ?string,
     *     usage: array<string, int>,
     *     safety: array<int, array<string, mixed>>,
     *     model: string,
     *     preset_id: ?int,
     *     mission_id: ?int
     * } Résultat normalisé de l'échange
     */
    public function ask(
        string $message,
        array $options = [],
        ?callable $onStatusUpdate = null,
        ?callable $onToken = null,
        ?callable $onToolExecuted = null,
    ): array {
        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return [
                'answer' => '',
                'debug_id' => null,
                'usage' => [],
                'safety' => [],
                'model' => 'unknown',
                'preset_id' => null,
                'mission_id' => null,
            ];
        }

        if ($onStatusUpdate) {
            $onStatusUpdate('Analyse de la demande...', 'thinking');
        }

        /** @var array{tone?: string, history?: array<int, array<string, mixed>>, stateless?: bool, debug?: bool, preset?: SynapsePreset, conversation_id?: string, user_id?: string, estimated_cost_reference?: float, streaming?: bool, reset_conversation?: bool} $askOptions */
        $askOptions = $options;

        // ── DISPATCH GENERATION STARTED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationStartedEvent($message, $askOptions));

        // ── DISPATCH PRE-PROMPT EVENT ──
        // ContextBuilderSubscriber will populate prompt, config, and tool definitions
        $prePromptEvent = $this->dispatcher->dispatch(new SynapsePrePromptEvent($message, $askOptions));
        /** @var array{contents: array<int, array<string, mixed>>, toolDefinitions?: array<int, array<string, mixed>>} $prompt */
        $prompt = $prePromptEvent->getPrompt();
        /** @var array<string, mixed> $config */
        $config = $prePromptEvent->getConfig();

        // Support preset override
        $presetOverride = $askOptions['preset'] ?? null;
        if ($presetOverride instanceof SynapsePreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        try {
            // Vérification des plafonds de dépense (avant appel LLM)
            $userId = $askOptions['user_id'] ?? null;
            $presetIdMixed = $config['preset_id'] ?? null;
            $presetId = is_numeric($presetIdMixed) ? (int) $presetIdMixed : null;
            $missionIdMixed = $config['mission_id'] ?? null;
            $missionId = is_numeric($missionIdMixed) ? (int) $missionIdMixed : null;
            if (null !== $this->spendingLimitChecker && is_string($userId)) {
                $estimatedCostRef = (float) ($askOptions['estimated_cost_reference'] ?? 0.0);
                $this->spendingLimitChecker->assertCanSpend($userId, $presetId, $estimatedCostRef, $missionId);
            }

            // Check debug mode
            $globalDebugMode = (bool) ($config['debug_mode'] ?? false);
            $debugMode = (bool) (($askOptions['debug'] ?? false) || ($globalDebugMode && ($askOptions['debug'] ?? true) !== false));

            // Get LLM client and config
            $activeClient = $this->llmRegistry->getClient();
            // $options['streaming'] permet de forcer le mode sync (false) ou streaming (true) indépendamment du preset
            $streamingEnabled = isset($askOptions['streaming']) ? (bool) $askOptions['streaming'] : ($config['streaming_enabled'] ?? true);

            // Ensure multi-turn usage is accumulated correctly
            $fullTextAccumulator = '';
            $cumulativeUsage = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'thinking_tokens' => 0,
                'total_tokens' => 0,
            ];
            $finalSafetyRatings = [];
            $debugId = null;

            $firstTurnRawData = []; // Capture raw API data from first turn

            // Multi-turn loop
            for ($turn = 0; $turn < self::MAX_TURNS; ++$turn) {
                if ($onStatusUpdate && $turn > 0) {
                    $onStatusUpdate('Réflexion supplémentaire...', 'thinking');
                }

                $debugOut = [];
                $hasToolCalls = false;

                // ── LLM CALL (Streaming or Sync) ──
                $this->profiler->start('LLM', 'LLM Network Call & Streaming', "Durée totale de l'échange réseau avec l'API du fournisseur (attente + réception des chunks).");
                /** @var array<int, array<string, mixed>> $contents */
                $contents = $prompt['contents'];
                /** @var array<int, array<string, mixed>> $toolDefinitions */
                $toolDefinitions = $prompt['toolDefinitions'] ?? [];

                if ($streamingEnabled) {
                    $chunks = $activeClient->streamGenerateContent(
                        $contents,
                        $toolDefinitions,
                        null,
                        $debugOut,
                    );
                } else {
                    $response = $activeClient->generateContent(
                        $contents,
                        $toolDefinitions,
                        null,
                        [],
                        $debugOut,
                    );
                    $chunks = [$response];
                }

                $modelText = '';
                $modelToolCalls = [];

                // ── PROCESS CHUNKS ──
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
                        $fullTextAccumulator .= $chunkText;
                        $modelText .= $chunkText;
                        if ($onToken) {
                            $onToken($chunkText);
                        }
                    }

                    // Handle thinking (add to model parts for history)
                    if (!empty($chunk['thinking'])) {
                        // Note: thinking is handled by native LLM thinking, not stored separately in history
                    }

                    // Accumulate usage across all turns (multi-turn = multiple LLM calls)
                    if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
                        $u = $chunk['usage'];
                        $cumulativeUsage['prompt_tokens'] += is_numeric($u['prompt_tokens'] ?? null) ? (int) $u['prompt_tokens'] : 0;
                        $cumulativeUsage['completion_tokens'] += is_numeric($u['completion_tokens'] ?? null) ? (int) $u['completion_tokens'] : 0;
                        $cumulativeUsage['thinking_tokens'] += is_numeric($u['thinking_tokens'] ?? null) ? (int) $u['thinking_tokens'] : 0;
                        $cumulativeUsage['total_tokens'] += is_numeric($u['total_tokens'] ?? null) ? (int) $u['total_tokens'] : 0;
                    }
                    if (!empty($chunk['safety_ratings']) && is_array($chunk['safety_ratings'])) {
                        $finalSafetyRatings = $chunk['safety_ratings'];
                    }

                    // Handle blocked responses
                    if ((bool) ($chunk['blocked'] ?? false)) {
                        $reason = is_string($chunk['blocked_reason'] ?? null) ? (string) $chunk['blocked_reason'] : 'contenu bloqué par les filtres de sécurité';
                        $blockedMsg = "⚠️ Ma réponse a été bloquée ({$reason}). Veuillez reformuler votre demande.";
                        $fullTextAccumulator .= $blockedMsg;
                        $modelText .= $blockedMsg;
                        if ($onToken) {
                            /** @var callable(string): void $tokenCallback */
                            $tokenCallback = $onToken;
                            $tokenCallback($blockedMsg);
                        }
                    }

                    // Collect function calls in OpenAI format
                    if (!empty($chunk['function_calls']) && is_array($chunk['function_calls'])) {
                        $hasToolCalls = true;
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
                                'id' => is_string($fc['id'] ?? null) ? (string) $fc['id'] : 'call_' . bin2hex(random_bytes(6)),
                                'type' => 'function',
                                'function' => [
                                    'name' => $name,
                                    'arguments' => $argsJson,
                                ],
                            ];
                        }
                    }
                }

                // ── RESOLVE TIMING ──
                // Compute actual time only after the generator is fully consumed (all chunks received)
                $this->profiler->stop('LLM', 'LLM Network Call & Streaming', $turn);

                // ── CAPTURE RAW DATA FROM FIRST TURN ──
                // Must be done AFTER the loop because $debugOut is populated by the generator during/after iteration
                if (0 === $turn && !empty($debugOut)) {
                    $firstTurnRawData = $debugOut;
                }

                // ── ADD MODEL RESPONSE TO HISTORY (OpenAI format) ──
                if ('' !== $modelText || !empty($modelToolCalls)) {
                    $entry = ['role' => 'assistant', 'content' => $modelText ?: null];
                    if (!empty($modelToolCalls)) {
                        $entry['tool_calls'] = $modelToolCalls;
                    }
                    $prompt['contents'][] = $entry;
                }

                // ── PROCESS TOOL CALLS ──
                if ($hasToolCalls && !empty($modelToolCalls)) {
                    // Dispatch ToolCallRequestedEvent - Normalise modelToolCalls to Event format
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

                    // Add tool responses to prompt for next iteration (one message per tool)
                    foreach ($modelToolCalls as $tc) {
                        $toolName = $tc['function']['name'];
                        $this->profiler->start('Tool', 'Tool Execution: ' . $toolName, "Exécution locale d'une fonction (outil) demandée par le LLM.");

                        $toolResult = $toolResults[$toolName] ?? null;

                        if ($onStatusUpdate) {
                            $onStatusUpdate("Exécution de l'outil: {$toolName}...", 'tool:' . $toolName);
                        }

                        if (null !== $toolResult) {
                            $this->dispatcher->dispatch(new SynapseToolCallCompletedEvent((string) $toolName, $toolResult, $tc));
                            $prompt['contents'][] = [
                                'role' => 'tool',
                                'tool_call_id' => (string) $tc['id'],
                                'content' => is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                            ];
                            // Callback pour notifier le contrôleur (permet de streamer l'événement immédiatement au frontend)
                            if ($onToolExecuted) {
                                $onToolExecuted((string) $toolName, $toolResult);
                            }
                        }

                        $this->profiler->stop('Tool', 'Tool Execution: ' . $toolName, $turn);
                    }

                    // Continuer la boucle : le LLM reçoit le résultat de l'outil et peut enchaîner avec sa réponse (ex. "Bonjour Arnaud, comment puis-je vous aider ?")
                    continue;
                }

                // No tool calls → end of exchange
                break;
            }

            // Ensure total_tokens is set (sum if not provided by API)
            if (0 === $cumulativeUsage['total_tokens']) {
                $cumulativeUsage['total_tokens'] = $cumulativeUsage['prompt_tokens']
                    + $cumulativeUsage['completion_tokens']
                    + $cumulativeUsage['thinking_tokens'];
            }
            $finalUsageMetadata = $cumulativeUsage;

            // ── FINALIZE AND LOG ──
            // $timings['total_ms'] = round((microtime(true) - $timeGlobalStart) * 1000, 2); // Removed, now handled by profiler

            if ($debugMode) {
                $debugId = uniqid('dbg_', true);

                $timings = $this->profiler->getTimings();

                // Dispatch completion event for debug logging
                // DebugLogSubscriber will handle cache storage and DB persistence
                $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                    $debugId,
                    is_string($config['model'] ?? null) ? (string) $config['model'] : 'unknown',
                    $activeClient->getProviderName(),
                    $finalUsageMetadata,
                    /* @var array<string, mixed> $finalSafetyRatings */
                    $finalSafetyRatings,
                    $debugMode,
                    $firstTurnRawData,
                    $timings
                ));
            }

            // Purge the current timers for next call
            $this->profiler->reset();

            // ── DISPATCH GENERATION COMPLETED EVENT ──
            $this->dispatcher->dispatch(new SynapseGenerationCompletedEvent(
                $fullTextAccumulator,
                $finalUsageMetadata,
                $debugId
            ));

            /** @var array<int, array<string, mixed>> $finalSafetyRatingsArray */
            $finalSafetyRatingsArray = $finalSafetyRatings;

            return [
                'answer' => $fullTextAccumulator,
                'debug_id' => $debugId,
                'usage' => $finalUsageMetadata,
                'safety' => $finalSafetyRatingsArray,
                'model' => is_string($config['model'] ?? null) ? (string) $config['model'] : (is_string($config['provider'] ?? null) ? (string) $config['provider'] : 'unknown'),
                'preset_id' => is_numeric($config['preset_id'] ?? null) ? (int) $config['preset_id'] : null,
                'mission_id' => is_numeric($config['mission_id'] ?? null) ? (int) $config['mission_id'] : null,
            ];
        } finally {
            // Garantit la réinitialisation de l'override même en cas d'exception
            // Critique en mode FrankenPHP worker : les services sont partagés entre requêtes
            if (null !== $presetOverride) {
                $this->configProvider->setOverride(null);
            }
        }
    }

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
