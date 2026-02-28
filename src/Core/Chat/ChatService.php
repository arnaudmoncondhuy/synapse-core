<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseGenerationCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseGenerationStartedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapsePrePromptEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use Doctrine\ORM\EntityManagerInterface;
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
        private ?\ArnaudMoncondhuy\SynapseCore\Core\Manager\ConversationManager $conversationManager = null,
        private ?\ArnaudMoncondhuy\SynapseCore\Core\Accounting\SpendingLimitChecker $spendingLimitChecker = null,
    ) {}

    /**
     * Point d'entrée principal pour envoyer un message à l'IA.
     *
     * Cette méthode gère l'orchestration complète : recherche du contexte, appel du client LLM,
     * exécution des outils (si nécessaire) et persistance des messages.
     *
     * @param string        $message Le texte envoyé par l'utilisateur.
     * @param array{
     *     tone?: string,
     *     history?: array,
     *     stateless?: bool,
     *     debug?: bool,
     *     preset?: SynapsePreset,
     *     conversation_id?: string
     * } $options Options contrôlant le comportement de l'échange.
     * @param callable|null $onStatusUpdate Callback appelé à chaque étape (thinking, tool_call, etc.) : fn(string $msg, string $step).
     * @param callable|null $onToken        Callback appelé à chaque token reçu (streaming) : fn(string $token).
     * @param callable|null $onToolExecuted Callback appelé après exécution d'un outil : fn(string $toolName, mixed $result).
     *
     * @return array{
     *     answer: string,
     *     debug_id: ?string,
     *     usage: array,
     *     safety: array,
     *     model: string
     * } Résultat normalisé de l'échange.
     */
    public function ask(
        string $message,
        array $options = [],
        ?callable $onStatusUpdate = null,
        ?callable $onToken = null,
        ?callable $onToolExecuted = null
    ): array {
        if (empty($message) && ($options['reset_conversation'] ?? false)) {
            return [
                'answer' => '',
                'debug_id' => null,
                'usage' => [],
                'safety' => [],
                'model' => 'unknown',
            ];
        }

        if ($onStatusUpdate) {
            $onStatusUpdate('Analyse de la demande...', 'thinking');
        }

        // ── DISPATCH GENERATION STARTED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationStartedEvent($message, $options));

        // ── DISPATCH PRE-PROMPT EVENT ──
        // ContextBuilderSubscriber will populate prompt, config, and tool definitions
        $prePromptEvent = $this->dispatcher->dispatch(new SynapsePrePromptEvent($message, $options));
        $prompt = $prePromptEvent->getPrompt();
        $config = $prePromptEvent->getConfig();

        // Support preset override
        $presetOverride = $options['preset'] ?? null;
        if ($presetOverride instanceof SynapsePreset) {
            $config = $this->configProvider->getConfigForPreset($presetOverride);
            $this->configProvider->setOverride($config);
        }

        // Vérification des plafonds de dépense (avant appel LLM)
        $userId = $options['user_id'] ?? null;
        $presetId = $config['preset_id'] ?? null;
        $missionId = isset($config['mission_id']) ? (int) $config['mission_id'] : null;
        if ($this->spendingLimitChecker !== null && is_string($userId)) {
            $estimatedCostRef = $options['estimated_cost_reference'] ?? 0.0;
            $this->spendingLimitChecker->assertCanSpend($userId, $presetId !== null ? (int) $presetId : null, (float) $estimatedCostRef, $missionId);
        }

        // Check debug mode
        $globalDebugMode = $config['debug_mode'] ?? false;
        $debugMode = ($options['debug'] ?? false) || ($globalDebugMode && ($options['debug'] ?? true) !== false);

        // Get LLM client and config
        $activeClient = $this->llmRegistry->getClient();
        $streamingEnabled = $config['streaming_enabled'] ?? true;

        // Accumulators (usage is accumulated across all turns for correct multi-turn counting)
        $fullTextAccumulator = '';
        $cumulativeUsage = [
            'prompt_tokens'     => 0,
            'completion_tokens' => 0,
            'thinking_tokens'   => 0,
            'total_tokens'      => 0,
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
            if ($streamingEnabled) {
                $chunks = $activeClient->streamGenerateContent(
                    $prompt['contents'],
                    $prompt['toolDefinitions'] ?? [],
                    null,
                    $debugOut,
                );
            } else {
                $response = $activeClient->generateContent(
                    $prompt['contents'],
                    $prompt['toolDefinitions'] ?? [],
                    null,
                    [],
                    $debugOut,
                );
                $chunks = [$response];
            }

            $modelText = '';
            $modelToolCalls = [];

            // ── PROCESS CHUNKS ──
            foreach ($chunks as $chunk) {
                // Dispatch ChunkReceivedEvent (for debug logging and streaming)
                $this->dispatcher->dispatch(new SynapseChunkReceivedEvent($chunk, $turn));

                // Accumulate text
                if (!empty($chunk['text'])) {
                    $fullTextAccumulator .= $chunk['text'];
                    $modelText .= $chunk['text'];
                    if ($onToken) {
                        $onToken($chunk['text']);
                    }
                }

                // Handle thinking (add to model parts for history)
                if (!empty($chunk['thinking'])) {
                    // Note: thinking is handled by native LLM thinking, not stored separately in history
                }

                // Accumulate usage across all turns (multi-turn = multiple LLM calls)
                if (!empty($chunk['usage'])) {
                    $u = $chunk['usage'];
                    $cumulativeUsage['prompt_tokens']     += (int) ($u['prompt_tokens'] ?? 0);
                    $cumulativeUsage['completion_tokens'] += (int) ($u['completion_tokens'] ?? 0);
                    $cumulativeUsage['thinking_tokens']   += (int) ($u['thinking_tokens'] ?? 0);
                    $cumulativeUsage['total_tokens']      += (int) ($u['total_tokens'] ?? 0);
                }
                if (!empty($chunk['safety_ratings'])) {
                    $finalSafetyRatings = $chunk['safety_ratings'];
                }

                // Handle blocked responses
                if ($chunk['blocked'] ?? false) {
                    $reason = $chunk['blocked_reason'] ?? 'contenu bloqué par les filtres de sécurité';
                    $blockedMsg = "⚠️ Ma réponse a été bloquée ({$reason}). Veuillez reformuler votre demande.";
                    $fullTextAccumulator .= $blockedMsg;
                    $modelText .= $blockedMsg;
                    if ($onToken) {
                        $onToken($blockedMsg);
                    }
                }

                // Collect function calls in OpenAI format
                if (!empty($chunk['function_calls'])) {
                    $hasToolCalls = true;
                    foreach ($chunk['function_calls'] as $fc) {
                        $name = $fc['name'] ?? $fc['function']['name'] ?? null;
                        if ($name === null || $name === '') {
                            continue;
                        }
                        $rawArgs = $fc['args'] ?? $fc['function']['arguments'] ?? [];
                        $argsJson = is_string($rawArgs) ? $rawArgs : json_encode($rawArgs, JSON_UNESCAPED_UNICODE);
                        $modelToolCalls[] = [
                            'id'       => $fc['id'] ?? 'call_' . bin2hex(random_bytes(6)),
                            'type'     => 'function',
                            'function' => [
                                'name'      => $name,
                                'arguments' => $argsJson,
                            ],
                        ];
                    }
                }
            }

            // ── CAPTURE RAW DATA FROM FIRST TURN ──
            // Must be done AFTER the loop because $debugOut is populated by the generator during/after iteration
            if ($turn === 0 && !empty($debugOut)) {
                $firstTurnRawData = $debugOut;
            }

            // ── ADD MODEL RESPONSE TO HISTORY (OpenAI format) ──
            if ($modelText !== '' || !empty($modelToolCalls)) {
                $entry = ['role' => 'assistant', 'content' => $modelText ?: null];
                if (!empty($modelToolCalls)) {
                    $entry['tool_calls'] = $modelToolCalls;
                }
                $prompt['contents'][] = $entry;
            }

            // ── PROCESS TOOL CALLS ──
            if ($hasToolCalls && !empty($modelToolCalls)) {
                // Dispatch ToolCallRequestedEvent - Normalise modelToolCalls to Event format
                $eventToolCalls = array_map(fn($tc) => [
                    'id'   => $tc['id'],
                    'name' => $tc['function']['name'],
                    'args' => (array) (json_decode($tc['function']['arguments'], true) ?: [])
                ], $modelToolCalls);

                $toolEvent = $this->dispatcher->dispatch(new SynapseToolCallRequestedEvent($eventToolCalls));
                $toolResults = $toolEvent->getResults();

                // Add tool responses to prompt for next iteration (one message per tool)
                foreach ($modelToolCalls as $tc) {
                    $toolName = $tc['function']['name'];
                    $toolResult = $toolResults[$toolName] ?? null;

                    if ($onStatusUpdate) {
                        $onStatusUpdate("Exécution de l'outil: {$toolName}...", 'tool:' . $toolName);
                    }

                    if (null !== $toolResult) {
                        $this->dispatcher->dispatch(new SynapseToolCallCompletedEvent($toolName, $toolResult, $tc));
                        $prompt['contents'][] = [
                            'role'         => 'tool',
                            'tool_call_id' => $tc['id'],
                            'content'      => is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE),
                        ];
                        // Callback pour notifier le contrôleur (permet de streamer l'événement immédiatement au frontend)
                        if ($onToolExecuted) {
                            $onToolExecuted($toolName, $toolResult);
                        }
                    }
                }

                // Continuer la boucle : le LLM reçoit le résultat de l'outil et peut enchaîner avec sa réponse (ex. "Bonjour Arnaud, comment puis-je vous aider ?")
                continue;
            }

            // No tool calls → end of exchange
            break;
        }

        // Ensure total_tokens is set (sum if not provided by API)
        if ($cumulativeUsage['total_tokens'] === 0) {
            $cumulativeUsage['total_tokens'] = $cumulativeUsage['prompt_tokens']
                + $cumulativeUsage['completion_tokens']
                + $cumulativeUsage['thinking_tokens'];
        }
        $finalUsageMetadata = $cumulativeUsage;

        // ── FINALIZE AND LOG ──
        if ($debugMode) {
            $debugId = uniqid('dbg_', true);

            // Dispatch completion event for debug logging
            // DebugLogSubscriber will handle cache storage and DB persistence
            $this->dispatcher->dispatch(new SynapseExchangeCompletedEvent(
                $debugId,
                $config['model'] ?? 'unknown',
                $activeClient->getProviderName(),
                $finalUsageMetadata,
                $finalSafetyRatings,
                $debugMode,
                $firstTurnRawData
            ));
        }

        // Reset preset override if applicable
        if ($presetOverride !== null) {
            $this->configProvider->setOverride(null);
        }

        // ── DISPATCH GENERATION COMPLETED EVENT ──
        $this->dispatcher->dispatch(new SynapseGenerationCompletedEvent(
            $fullTextAccumulator,
            $finalUsageMetadata,
            $debugId
        ));

        return [
            'answer'     => $fullTextAccumulator,
            'debug_id'   => $debugId,
            'usage'      => $finalUsageMetadata,
            'safety'     => $finalSafetyRatings,
            'model'      => $config['model'] ?? ($config['provider'] ?? 'unknown'),
            'preset_id'  => $config['preset_id'] ?? null,
            'mission_id' => $config['mission_id'] ?? null,
        ];
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
     * Récupère l'historique de conversation complet au format OpenAI.
     *
     * @return array<int, array{role: string, content: string|null, tool_calls?: array, tool_call_id?: string}> Messages au format OpenAI
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
