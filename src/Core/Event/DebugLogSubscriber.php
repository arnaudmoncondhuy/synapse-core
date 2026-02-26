<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\SynapseDebugLoggerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapseExchangeCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Core\Event\SynapsePrePromptEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use DateTimeImmutable;

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
    private array $debugAccumulator = [];

    public function __construct(
        private SynapseDebugLoggerInterface $debugLogger,
        private CacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class         => ['onPrePrompt', 50],
            SynapseChunkReceivedEvent::class     => ['onChunkReceived', 0],
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
        $contents = $prompt['contents'] ?? [];
        if (!empty($contents) && ($contents[0]['role'] ?? '') === 'system') {
            $systemInstruction = $contents[0]['content'] ?? null;
        }

        // Extract preset config parameters for display
        $safetySettings = $config['safety_settings'] ?? [];
        $presetConfig = [
            'model'              => $config['model'] ?? null,
            'provider'           => $config['provider'] ?? null,
            'temperature'        => $config['generation_config']['temperature'] ?? null,
            'top_p'              => $config['generation_config']['top_p'] ?? null,
            'top_k'              => $config['generation_config']['top_k'] ?? null,
            'max_output_tokens'  => $config['generation_config']['max_output_tokens'] ?? null,
            'thinking_enabled'   => $config['thinking']['enabled'] ?? false,
            'thinking_budget'    => $config['thinking']['budget'] ?? null,
            'safety_enabled'     => $safetySettings['enabled'] ?? false,
            'safety_thresholds'  => $safetySettings['thresholds'] ?? [],
            'safety_default_threshold' => $safetySettings['default_threshold'] ?? null,
            'tools_sent'         => !empty($prompt['toolDefinitions']),
            'streaming_enabled'  => $config['streaming_enabled'] ?? false,
        ];

        // Capture tool definitions for display
        $toolDefinitions = $prompt['toolDefinitions'] ?? [];

        $this->debugAccumulator = [
            'system_prompt'       => $systemInstruction,
            'config'              => $config,
            'preset_config'       => $presetConfig,
            'history'             => $prompt['contents'] ?? [],
            'history_size'        => count($prompt['contents'] ?? []),
            'turns'               => [],
            'tool_executions'     => [],
            'tool_definitions'    => $toolDefinitions,
            'raw_request_body'    => null,
            'raw_response'        => [],
            'raw_api_chunks'      => [],
            'raw_api_response'    => null,
        ];
    }

    public function onChunkReceived(SynapseChunkReceivedEvent $event): void
    {
        $chunk = $event->getChunk();
        $rawChunk = $event->getRawChunk();
        $turn = $event->getTurn();

        // Accumulate raw API chunks for debug
        if ($rawChunk !== null) {
            $this->debugAccumulator['raw_api_chunks'][] = $rawChunk;
        }

        // Accumulate normalized response chunks
        $this->debugAccumulator['raw_response'][] = $chunk;

        // Initialize turn if not exists
        if (!isset($this->debugAccumulator['turns'][$turn])) {
            $this->debugAccumulator['turns'][$turn] = [
                'turn'           => $turn,
                'text'           => '',
                'thinking'       => '',
                'function_calls' => [],
                'usage'          => [],
                'safety_ratings' => [],
            ];
        }

        // Accumulate text and thinking
        if (!empty($chunk['text'])) {
            $this->debugAccumulator['turns'][$turn]['text'] .= $chunk['text'];
        }
        if (!empty($chunk['thinking'])) {
            $this->debugAccumulator['turns'][$turn]['thinking'] .= $chunk['thinking'];
        }

        // Accumulate function calls
        $functionCalls = $chunk['function_calls'] ?? [];
        if (!empty($functionCalls)) {
            $this->debugAccumulator['turns'][$turn]['function_calls'] = array_merge(
                $this->debugAccumulator['turns'][$turn]['function_calls'],
                $functionCalls
            );
        }

        // Merge usage stats
        if (!empty($chunk['usage'])) {
            $this->debugAccumulator['turns'][$turn]['usage'] = array_merge(
                $this->debugAccumulator['turns'][$turn]['usage'] ?? [],
                $chunk['usage']
            );
        }

        // Merge safety ratings
        if (!empty($chunk['safety_ratings'])) {
            $this->debugAccumulator['turns'][$turn]['safety_ratings'] = array_merge(
                $this->debugAccumulator['turns'][$turn]['safety_ratings'] ?? [],
                $chunk['safety_ratings']
            );
        }
    }

    public function onExchangeCompleted(SynapseExchangeCompletedEvent $event): void
    {
        // Only log if debug mode is enabled
        if (!$event->isDebugMode()) {
            $this->debugAccumulator = [];
            return;
        }

        // Merge raw API data captured from the LLM client
        $rawData = $event->getRawData();
        if (!empty($rawData)) {
            if (!empty($rawData['raw_request_body'])) {
                $this->debugAccumulator['raw_request_body'] = $rawData['raw_request_body'];
            }
            if (!empty($rawData['raw_api_chunks'])) {
                $this->debugAccumulator['raw_api_chunks'] = $rawData['raw_api_chunks'];
            }
            if (!empty($rawData['raw_api_response'])) {
                $this->debugAccumulator['raw_api_response'] = $rawData['raw_api_response'];
            }
        }

        // Extract tool usage from history (which tool calls were made and their results)
        $this->debugAccumulator['tool_usage'] = $this->extractToolUsage();

        // Complete the debug accumulator with final metadata
        $this->debugAccumulator['model']    = $event->getModel();
        $this->debugAccumulator['provider'] = $event->getProvider();
        $this->debugAccumulator['usage']    = $event->getUsage();
        $this->debugAccumulator['safety']   = $event->getSafety();
        $this->debugAccumulator['created_at'] = new DateTimeImmutable();

        // Prepare lightweight metadata for DB storage
        $metadata = [
            'model'              => $event->getModel(),
            'provider'           => $event->getProvider(),
            'token_usage'        => $event->getUsage(),
            'safety_ratings'     => $event->getSafety(),
            'thinking_enabled'   => $this->debugAccumulator['config']['thinking_enabled'] ?? false,
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
        $this->debugAccumulator = [];
    }

    /**
     * Extract tool usage from conversation history.
     * 
     * Pairs assistant tool_calls with their corresponding tool results.
     */
    private function extractToolUsage(): array
    {
        $toolUsage = [];
        $history = $this->debugAccumulator['history'] ?? [];

        // Build a map of tool_call_id => tool call info
        $toolCallMap = [];
        foreach ($history as $msg) {
            if ($msg['role'] === 'assistant' && !empty($msg['tool_calls'])) {
                foreach ($msg['tool_calls'] as $tc) {
                    $toolCallId = $tc['id'] ?? null;
                    if ($toolCallId) {
                        $toolCallMap[$toolCallId] = [
                            'function_name' => $tc['function']['name'] ?? null,
                            'function_args' => $tc['function']['arguments'] ?? null,
                        ];
                    }
                }
            }
        }

        // Now find tool results paired with these calls
        foreach ($history as $msg) {
            if ($msg['role'] === 'tool' && !empty($msg['tool_call_id'])) {
                $toolCallId = $msg['tool_call_id'];
                if (isset($toolCallMap[$toolCallId])) {
                    $toolUsage[] = [
                        'tool_call_id'   => $toolCallId,
                        'tool_name'      => $toolCallMap[$toolCallId]['function_name'],
                        'tool_args'      => $toolCallMap[$toolCallId]['function_args'],
                        'tool_result'    => $msg['content'] ?? null,
                    ];
                }
            }
        }

        return $toolUsage;
    }
}
