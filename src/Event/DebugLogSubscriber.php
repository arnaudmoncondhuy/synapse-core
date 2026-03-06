<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Accumulates debug data throughout the LLM exchange and persists it.
 *
 * Listens to:
 * - SynapsePrePromptEvent: captures system prompt and initial config
 * - SynapseChunkReceivedEvent: accumulates chunk data (tokens, thinking, tool calls)
 * - SynapseExchangeCompletedEvent: persists final debug data via logger
 */
class DebugLogSubscriber implements EventSubscriberInterface
{
    /** @var array<string, mixed> */
    private array $debugAccumulator = [];

    public function __construct(
        private SynapseDebugLoggerInterface $debugLogger,
        private CacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', 0],   // Lower priority to capture prompt AFTER MemoryContextSubscriber (50)
            SynapseChunkReceivedEvent::class => ['onChunkReceived', 0],
            SynapseToolCallCompletedEvent::class => ['onToolCallCompleted', 0],
            SynapseExchangeCompletedEvent::class => ['onExchangeCompleted', -100], // Low priority, let others finish first
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
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
        $genConfig = is_array($config['generation_config'] ?? null) ? $config['generation_config'] : [];
        $thinking = is_array($config['thinking'] ?? null) ? $config['thinking'] : [];
        $safetySettings = is_array($config['safety_settings'] ?? null) ? $config['safety_settings'] : [];

        $presetConfig = [
            'model' => is_string($config['model'] ?? null) ? $config['model'] : null,
            'provider' => is_string($config['provider'] ?? null) ? $config['provider'] : null,
            'temperature' => $genConfig['temperature'] ?? null,
            'top_p' => $genConfig['top_p'] ?? null,
            'top_k' => $genConfig['top_k'] ?? null,
            'max_output_tokens' => $genConfig['max_output_tokens'] ?? null,
            'thinking_enabled' => (bool) ($thinking['enabled'] ?? false),
            'thinking_budget' => $thinking['budget'] ?? null,
            'safety_enabled' => (bool) ($safetySettings['enabled'] ?? false),
            'safety_thresholds' => is_array($safetySettings['thresholds'] ?? null) ? $safetySettings['thresholds'] : [],
            'safety_default_threshold' => is_string($safetySettings['default_threshold'] ?? null) ? $safetySettings['default_threshold'] : null,
            'tools_sent' => !empty($prompt['toolDefinitions']),
            'streaming_enabled' => (bool) ($config['streaming_enabled'] ?? false),
        ];

        // Capture tool definitions for display
        $toolDefinitionsRaw = $prompt['toolDefinitions'] ?? [];
        $toolDefinitions = is_array($toolDefinitionsRaw) ? $toolDefinitionsRaw : [];

        // Capture global prompt metadata (e.g. Memory matching details)
        $promptMetadataRaw = $prompt['metadata'] ?? [];
        $promptMetadata = is_array($promptMetadataRaw) ? $promptMetadataRaw : [];

        /** @var array<string, mixed> $accumulatorData */
        $accumulatorData = [
            'system_prompt' => $systemInstruction,
            'config' => $config,
            'preset_config' => $presetConfig,
            'history' => $messages,
            'history_size' => count($messages),
            'prompt_metadata' => $promptMetadata,
            'turns' => [],
            'tool_executions' => [],
            'tool_definitions' => array_values(array_filter(is_array($toolDefinitions) ? $toolDefinitions : [], fn($v) => is_array($v))),
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
        $toolCallDataRaw = $event->getToolCallData();
        $toolCallData = is_array($toolCallDataRaw) ? (array) $toolCallDataRaw : [];
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
        if (!empty($rawData) && is_array($rawData)) {
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
        }

        // Use captured tool executions
        $this->debugAccumulator['tool_usage'] = $this->debugAccumulator['tool_executions'] ?? [];

        // Complete the debug accumulator with final metadata
        $this->debugAccumulator['model'] = $event->getModel();
        $this->debugAccumulator['provider'] = $event->getProvider();
        $this->debugAccumulator['usage'] = $event->getUsage();
        $this->debugAccumulator['safety'] = $event->getSafety();
        $this->debugAccumulator['timings'] = $event->getTimings();
        $this->debugAccumulator['created_at'] = new \DateTimeImmutable();

        // Prepare lightweight metadata for DB storage
        $metadata = [
            'model' => $event->getModel(),
            'provider' => $event->getProvider(),
            'token_usage' => $event->getUsage(),
            'safety_ratings' => $event->getSafety(),
            'timings' => $event->getTimings(),
            'thinking_enabled' => (isset($this->debugAccumulator['config']) && is_array($this->debugAccumulator['config'])) ? ($this->debugAccumulator['config']['thinking_enabled'] ?? false) : false,
        ];

        // Pass COMPLETE debug data (not just metadata) for template rendering
        $this->debugLogger->logExchange($event->getDebugId(), $metadata, $this->debugAccumulator);

        // Store complete debug data in cache for quick retrieval (1 day TTL)
        $debugId = $event->getDebugId();
        $this->cache->get("synapse_debug_{$debugId}", function (ItemInterface $item) {
            $item->expiresAfter(86400); // 24 hours

            return $this->debugAccumulator;
        });

        // Clean up
        /** @var array<string, mixed> $accumulatorData */
        $accumulatorData = [];
    }
}
