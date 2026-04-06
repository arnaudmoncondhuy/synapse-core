<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptCaptureEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Accumulates debug data throughout the LLM exchange and persists it.
 *
 * Listens to:
 * - PromptCaptureEvent (phase CAPTURE) : captures final prompt and config after all pipeline phases
 * - SynapseChunkReceivedEvent          : accumulates chunk data (tokens, thinking, tool calls)
 * - SynapseToolCallCompletedEvent      : records tool executions
 * - SynapseExchangeCompletedEvent      : persists final debug data via logger
 */
class DebugLogSubscriber implements EventSubscriberInterface
{
    /** @var array<string, mixed> */
    private array $debugAccumulator = [];

    public function __construct(
        private SynapseDebugLoggerInterface $debugLogger,
        private CacheInterface $cache,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PromptCaptureEvent::class => ['onPrePrompt', 0], // Phase CAPTURE : toujours après FINALIZE
            SynapseChunkReceivedEvent::class => ['onChunkReceived', 0],
            SynapseToolCallCompletedEvent::class => ['onToolCallCompleted', 0],
            SynapseExchangeCompletedEvent::class => ['onExchangeCompleted', -100], // Low priority, let others finish first
        ];
    }

    public function onPrePrompt(PromptCaptureEvent $event): void
    {
        // Capture initial context for debug
        $prompt = $event->getPrompt();
        $config = $event->getConfig();

        // Extract system instruction from contents (first message with role: 'system')
        $systemInstruction = null;
        $contents = $event->getPrompt();
        $messages = is_array($contents['contents'] ?? null) ? $contents['contents'] : (isset($contents[0]) && is_array($contents[0]) ? $contents : []);
        if (!empty($messages) && is_array($messages[0] ?? null) && ($messages[0]['role'] ?? '') === 'system') {
            $systemInstruction = is_string($messages[0]['content'] ?? null) ? (string) $messages[0]['content'] : null;
        }

        // Extract preset config parameters for display
        $presetConfig = null !== $config ? [
            'preset_id' => $config->presetId,
            'preset_name' => $config->presetName,
            'agent_id' => $config->agentId,
            'agent_name' => $config->agentName,
            'agent_emoji' => $config->agentEmoji,
            'active_tone' => $config->activeTone,
            'model' => $config->model,
            'provider' => $config->provider,
            'temperature' => $config->generation->temperature,
            'top_p' => $config->generation->topP,
            'top_k' => $config->generation->topK,
            'max_output_tokens' => $config->generation->maxOutputTokens,
            'thinking_enabled' => $config->thinking->enabled,
            'thinking_budget' => $config->thinking->budget,
            'safety_enabled' => $config->safety->enabled,
            'safety_thresholds' => $config->safety->thresholds,
            'safety_default_threshold' => $config->safety->defaultThreshold,
            'tools_sent' => !empty($prompt['toolDefinitions']),
            'streaming_enabled' => $config->streamingEnabled,
            'pricing_input' => $config->pricingInput,
            'pricing_output' => $config->pricingOutput,
        ] : [];

        // Capture tool definitions for display
        $toolDefinitionsRaw = $prompt['toolDefinitions'] ?? [];
        $toolDefinitions = is_array($toolDefinitionsRaw) ? $toolDefinitionsRaw : [];

        // Capture global prompt metadata (e.g. Memory matching details)
        $promptMetadataRaw = $prompt['metadata'] ?? [];
        $promptMetadata = is_array($promptMetadataRaw) ? $promptMetadataRaw : [];

        /** @var array<string, mixed> $accumulatorData */
        $accumulatorData = [
            'system_prompt' => $systemInstruction,
            'config' => $config?->toArray(),
            'preset_config' => $presetConfig,
            'history' => $messages,
            'history_size' => count($messages),
            'prompt_metadata' => $promptMetadata,
            'turns' => [],
            'tool_executions' => [],
            'tool_definitions' => array_values(array_filter($toolDefinitions, fn ($v) => is_array($v))),
            'raw_request_body' => null,
            'raw_response' => [],
            'raw_api_chunks' => [],
            'raw_api_response' => null,
        ];
        $this->debugAccumulator = $accumulatorData;
    }

    public function onChunkReceived(SynapseChunkReceivedEvent $event): void
    {
        $chunk = $event->getChunk();
        $rawChunk = $event->getRawChunk();
        $turn = $event->getTurn();

        // Accumulate raw API chunks for debug
        if (null !== $rawChunk) {
            $this->debugAccumulator['raw_api_chunks'][] = $rawChunk;
        }

        // Accumulate normalized response chunks
        $this->debugAccumulator['raw_response'][] = $chunk;

        // Initialize turn if not exists
        if (!isset($this->debugAccumulator['turns'][$turn])) {
            $this->debugAccumulator['turns'][$turn] = [
                'turn' => $turn,
                'text' => '',
                'thinking' => '',
                'function_calls' => [],
                'usage' => [],
                'safety_ratings' => [],
            ];
        }

        /** @var array{turn: int, text: string, thinking: string, function_calls: array<mixed>, usage: array<mixed>, safety_ratings: array<mixed>} $turnData */
        $turnData = $this->debugAccumulator['turns'][$turn];

        // Accumulate text and thinking
        if (!empty($chunk['text'])) {
            $turnData['text'] .= (string) $chunk['text'];
        }
        if (!empty($chunk['thinking'])) {
            $turnData['thinking'] .= (string) $chunk['thinking'];
        }

        // Accumulate function calls
        if (!empty($chunk['function_calls'])) {
            $functionCalls = $chunk['function_calls'];
            $turnData['function_calls'] = array_merge(
                $turnData['function_calls'],
                $functionCalls
            );
        }

        // Merge usage stats
        if (!empty($chunk['usage'])) {
            $turnData['usage'] = array_merge(
                $turnData['usage'],
                $chunk['usage']
            );
        }

        // Merge safety ratings
        if (!empty($chunk['safety_ratings'])) {
            $turnData['safety_ratings'] = array_merge(
                $turnData['safety_ratings'],
                $chunk['safety_ratings']
            );
        }

        $this->debugAccumulator['turns'][$turn] = $turnData;
    }

    public function onToolCallCompleted(SynapseToolCallCompletedEvent $event): void
    {
        $toolCallData = (array) $event->getToolCallData();
        $functionData = is_array($toolCallData['function'] ?? null) ? $toolCallData['function'] : [];

        $this->debugAccumulator['tool_executions'][] = [
            'tool_call_id' => is_scalar($toolCallData['id'] ?? null) ? (string) $toolCallData['id'] : null,
            'tool_name' => $event->getToolName(),
            'tool_args' => is_string($functionData['arguments'] ?? null)
                ? (string) $functionData['arguments']
                : json_encode($functionData['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
            'tool_result' => is_string($event->getResult())
                ? (string) $event->getResult()
                : json_encode($event->getResult(), JSON_UNESCAPED_UNICODE),
        ];
    }

    public function onExchangeCompleted(SynapseExchangeCompletedEvent $event): void
    {
        // Only log if debug mode is enabled
        if (!$event->isDebugMode()) {
            /** @var array<string, mixed> $accumulatorData */
            $accumulatorData = [];

            return;
        }

        // Merge raw API data captured from the LLM client
        $rawData = $event->getRawData();
        if (!empty($rawData)) {
            if (!empty($rawData['raw_request_body'])) {
                $this->debugAccumulator['raw_request_body'] = $rawData['raw_request_body'];
            }
            if (!empty($rawData['raw_api_chunks']) && is_array($rawData['raw_api_chunks'])) {
                /** @var array<int, array<string, mixed>> $apiChunks */
                $apiChunks = $rawData['raw_api_chunks'];
                $this->debugAccumulator['raw_api_chunks'] = $apiChunks;
            }
            if (!empty($rawData['raw_api_response'])) {
                $this->debugAccumulator['raw_api_response'] = $rawData['raw_api_response'];
            }
            if (!empty($rawData['generated_images']) && is_array($rawData['generated_images'])) {
                $this->debugAccumulator['generated_images'] = $rawData['generated_images'];
            }
        }

        // Use captured tool executions
        $this->debugAccumulator['tool_usage'] = $this->debugAccumulator['tool_executions'] ?? [];

        // Complete the debug accumulator with final metadata
        $usage = $event->getUsage();
        $this->debugAccumulator['model'] = $event->getModel();
        $this->debugAccumulator['provider'] = $event->getProvider();
        $this->debugAccumulator['usage'] = $usage->toArray();
        $this->debugAccumulator['safety'] = $event->getSafety();
        $this->debugAccumulator['timings'] = $event->getTimings();
        $this->debugAccumulator['created_at'] = new \DateTimeImmutable();

        // Calcul du coût estimé à partir du pricing et des tokens
        $presetCfg = $this->debugAccumulator['preset_config'] ?? [];
        $pricingIn = is_numeric($presetCfg['pricing_input'] ?? null) ? (float) $presetCfg['pricing_input'] : null;
        $pricingOut = is_numeric($presetCfg['pricing_output'] ?? null) ? (float) $presetCfg['pricing_output'] : null;
        if (null !== $pricingIn && null !== $pricingOut) {
            $cost = ($usage->promptTokens / 1_000_000) * $pricingIn + ($usage->completionTokens / 1_000_000) * $pricingOut;
            $this->debugAccumulator['estimated_cost'] = round($cost, 6);
            $this->debugAccumulator['estimated_cost_currency'] = 'USD';
        }

        // Module & action : propagés par ChatService depuis les options d'appel ($askOptions).
        // Utilisés pour dénormaliser `synapse_debug_log.module` / `.action` (affichage liste debug)
        // avec le MÊME vocabulaire que `synapse_llm_call` (Analytics). Source unique : ChatService.
        $module = $event->getModule();
        $action = $event->getAction();
        if (null !== $module) {
            $this->debugAccumulator['module'] = $module;
        }
        if (null !== $action) {
            $this->debugAccumulator['action'] = $action;
        }

        // Prepare lightweight metadata for DB storage
        $metadata = [
            'model' => $event->getModel(),
            'provider' => $event->getProvider(),
            'token_usage' => $usage->toArray(),
            'safety_ratings' => $event->getSafety(),
            'timings' => $event->getTimings(),
            'thinking_enabled' => (isset($this->debugAccumulator['config']) && is_array($this->debugAccumulator['config'])) ? ($this->debugAccumulator['config']['thinking_enabled'] ?? false) : false,
            'module' => $module,
            'action' => $action,
        ];

        // Enrichissement avec le contexte agent (traçabilité arborescente).
        // Absent = appel racine non-agent → valeurs par défaut (depth=0, origin='direct').
        $agentContext = $event->getAgentContext();
        if (null !== $agentContext) {
            $metadata['agent_run_id'] = $agentContext->getRequestId();
            $metadata['parent_run_id'] = $agentContext->getParentRunId();
            $metadata['depth'] = $agentContext->getDepth();
            $metadata['origin'] = $agentContext->getOrigin();
            // Propagation vers la colonne dénormalisée `synapse_debug_log.workflow_run_id`
            // (Phase 7). NULL si l'appel n'a pas été déclenché depuis un workflow.
            $metadata['workflow_run_id'] = $agentContext->getWorkflowRunId();
            // Propagate into rawPayload too so that templates/exports have access
            $this->debugAccumulator['agent_context'] = [
                'request_id' => $agentContext->getRequestId(),
                'parent_run_id' => $agentContext->getParentRunId(),
                'workflow_run_id' => $agentContext->getWorkflowRunId(),
                'depth' => $agentContext->getDepth(),
                'max_depth' => $agentContext->getMaxDepth(),
                'origin' => $agentContext->getOrigin(),
                'user_id' => $agentContext->getUserId(),
            ];
        }

        // Pass COMPLETE debug data (not just metadata) for template rendering
        $this->debugLogger->logExchange($event->getDebugId(), $metadata, $this->debugAccumulator);

        // Store complete debug data in cache for quick retrieval (1 day TTL)
        // Sanitize UTF-8 in all debug data to prevent serialization errors
        $debugId = $event->getDebugId();
        $cleanedDebugData = \ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil::sanitizeArrayUtf8($this->debugAccumulator);
        $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) use ($cleanedDebugData) {
            $item->expiresAfter(86400); // 24 hours

            return $cleanedDebugData;
        });

        // Clean up
        /** @var array<string, mixed> $accumulatorData */
        $accumulatorData = [];
    }
}
