<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Provider\Anthropic;

use ArnaudMoncondhuy\SynapseCore\Client\AbstractLlmClient;
use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\RgpdAwareInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\RgpdInfo;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour l'API Anthropic Messages (Claude).
 *
 * Le format entrant Synapse est OpenAI canonical ; ce client convertit vers
 * le format Anthropic avant l'appel API, puis normalise les réponses vers le
 * format Synapse (text / thinking / function_calls / usage).
 *
 * Différences principales avec OpenAI :
 * - `system` est un champ séparé (pas un message dans `messages`)
 * - Les messages `assistant` contiennent des blocs typés (`text`, `tool_use`, `thinking`)
 * - Les résultats de tools passent par un message `user` avec des blocs `tool_result`
 * - L'auth utilise `x-api-key` + `anthropic-version` au lieu de `Bearer`
 * - Le streaming SSE utilise des event types dédiés (`message_start`, `content_block_delta`…)
 *
 * Endpoint  : https://api.anthropic.com/v1/messages
 * Docs API  : https://docs.anthropic.com/en/api/messages
 */
#[Autoconfigure(tags: ['synapse.llm_client'])]
class AnthropicClient extends AbstractLlmClient implements RgpdAwareInterface
{
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const DEFAULT_MAX_TOKENS = 4096;

    private string $model = 'claude-sonnet-4-6';
    private string $apiKey = '';
    private string $endpoint = 'https://api.anthropic.com/v1';
    private float $temperature = 1.0;
    private float $topP = 0.95;
    private ?int $maxTokens = null;
    /** @var string[] */
    private array $stopSequences = [];
    private bool $thinkingEnabled = false;
    private int $thinkingBudget = 1024;

    public function __construct(
        HttpClientInterface $httpClient,
        ConfigProviderInterface $configProvider,
        ModelCapabilityRegistry $capabilityRegistry,
    ) {
        parent::__construct($httpClient, $configProvider, $capabilityRegistry);
    }

    public function getProviderName(): string
    {
        return 'anthropic';
    }

    /**
     * Génère du contenu en mode streaming (SSE Anthropic).
     * Yield des chunks normalisés au format Synapse.
     */
    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array $options = [],
        array &$debugOut = [],
    ): \Generator {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        [$system, $anthropicMessages] = $this->toAnthropicMessages($contents);
        $payload = $this->buildPayload($effectiveModel, $anthropicMessages, $system, $tools, $caps, true);

        $debugOut['actual_request_params'] = [
            'model' => $effectiveModel,
            'provider' => 'anthropic',
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => null,
            'max_output_tokens' => $this->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'thinking_enabled' => $this->thinkingEnabled && $caps->supportsThinking,
            'thinking_budget' => ($this->thinkingEnabled && $caps->supportsThinking) ? $this->thinkingBudget : null,
            'reasoning_effort' => null,
            'safety_enabled' => false,
            'tools_sent' => !empty($tools) && $caps->supportsFunctionCalling,
            'system_prompt_sent' => '' !== $system,
            'context_caching' => false,
        ];
        $debugOut['raw_request_body'] = TextUtil::sanitizeArrayUtf8($payload);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->endpoint, '/').'/messages', [
                'headers' => $this->buildHeaders(),
                'json' => $payload,
                'timeout' => self::HTTP_TIMEOUT_GENERATION,
                'buffer' => false,
            ]);

            /**
             * Content blocks accumulator, indexed by block index.
             *
             * @var array<int, array{type: string, text: string, thinking: string, signature: string, tool_use_id: string, tool_use_name: string, tool_use_input_raw: string}> $contentBlocks
             */
            $contentBlocks = [];
            /** @var array{prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int} $streamUsage */
            $streamUsage = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'thinking_tokens' => 0,
                'total_tokens' => 0,
            ];
            $buffer = '';
            $rawApiChunks = [];
            $streamingComplete = false;

            // Vérifier le status code avant de streamer — sur une 4xx/5xx,
            // le body d'erreur est lisible ici (avant que le buffer ne soit consommé).
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $errorBody = $response->getContent(false);
                $message = $this->parseErrorBody($errorBody, "HTTP {$statusCode}");
                $this->throwClassifiedException($statusCode, $message);
            }

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($streamingComplete) {
                    break;
                }

                try {
                    $buffer .= $chunk->getContent();
                } catch (\Throwable $e) {
                    $this->handleException($e);
                }

                while (($nlPos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nlPos);
                    $buffer = substr($buffer, $nlPos + 1);
                    $line = rtrim($line, "\r");

                    if ('' === $line || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonStr = substr($line, 6);
                    $data = json_decode($jsonStr, true);
                    if (!is_array($data)) {
                        continue;
                    }

                    $rawApiChunks[] = $data;

                    /** @var array<string, mixed> $eventData */
                    $eventData = $data;
                    $result = $this->processStreamEvent($eventData, $contentBlocks, $streamUsage);

                    if (null !== $result) {
                        yield $result;
                    }

                    if ('message_stop' === ($eventData['type'] ?? null)) {
                        $streamingComplete = true;
                        break;
                    }
                }
            }

            // Flush des tool calls accumulés (sécurité si message_stop arrive sans content_block_stop)
            $pendingToolCalls = $this->extractPendingToolCalls($contentBlocks);
            if (!empty($pendingToolCalls)) {
                $chunk = $this->emptyChunk();
                $chunk['function_calls'] = $pendingToolCalls;
                yield $chunk;
            }

            // Yield final usage chunk (combine input_tokens + output_tokens accumulés)
            if ($streamUsage['prompt_tokens'] > 0 || $streamUsage['completion_tokens'] > 0) {
                $streamUsage['total_tokens'] = $streamUsage['prompt_tokens'] + $streamUsage['completion_tokens'];
                $finalChunk = $this->emptyChunk();
                $finalChunk['usage'] = $streamUsage;
                yield $finalChunk;
            }

            $debugOut['raw_api_chunks'] = TextUtil::sanitizeArrayUtf8($rawApiChunks);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Génère du contenu en mode synchrone (non-streaming).
     */
    public function generateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array $options = [],
        array &$debugOut = [],
    ): array {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        [$system, $anthropicMessages] = $this->toAnthropicMessages($contents);
        $payload = $this->buildPayload($effectiveModel, $anthropicMessages, $system, $tools, $caps, false);

        $debugOut['actual_request_params'] = [
            'model' => $effectiveModel,
            'provider' => 'anthropic',
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => null,
            'max_output_tokens' => $this->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'thinking_enabled' => $this->thinkingEnabled && $caps->supportsThinking,
            'thinking_budget' => ($this->thinkingEnabled && $caps->supportsThinking) ? $this->thinkingBudget : null,
            'reasoning_effort' => null,
            'safety_enabled' => false,
            'tools_sent' => !empty($tools) && $caps->supportsFunctionCalling,
            'system_prompt_sent' => '' !== $system,
            'context_caching' => false,
        ];
        $debugOut['raw_request_body'] = TextUtil::sanitizeArrayUtf8($payload);

        try {
            $response = $this->httpClient->request('POST', rtrim($this->endpoint, '/').'/messages', [
                'headers' => $this->buildHeaders(),
                'json' => $payload,
                'timeout' => self::HTTP_TIMEOUT_GENERATION,
            ]);

            $data = $response->toArray();
            $debugOut['raw_api_response'] = TextUtil::sanitizeArrayUtf8($data);

            return $this->normalizeCompletionResponse($data);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Convertit les messages OpenAI canonical vers le format Anthropic.
     *
     * Retourne un tuple [string $system, array $messages] :
     *   - $system : contenu du message système (chaîne, '' si absent)
     *   - $messages : messages au format Anthropic (roles user/assistant uniquement)
     *
     * @param array<int, array<string, mixed>> $openAiMessages
     *
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function toAnthropicMessages(array $openAiMessages): array
    {
        $system = '';
        $messages = [];
        /** @var array<int, array<string, mixed>> $pendingToolResults */
        $pendingToolResults = [];

        foreach ($openAiMessages as $msg) {
            $role = is_string($msg['role'] ?? null) ? (string) $msg['role'] : '';

            if ('system' === $role) {
                $content = $msg['content'] ?? '';
                $system = is_scalar($content) ? (string) $content : (string) json_encode($content);
                continue;
            }

            if ('tool' === $role) {
                $content = $msg['content'] ?? '';
                $toolContent = is_scalar($content) ? (string) $content : (string) json_encode($content);
                $pendingToolResults[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => is_string($msg['tool_call_id'] ?? null) ? (string) $msg['tool_call_id'] : '',
                    'content' => $toolContent,
                ];
                continue;
            }

            // Flush des tool results accumulés avant le prochain message non-tool
            if (!empty($pendingToolResults)) {
                $messages[] = ['role' => 'user', 'content' => $pendingToolResults];
                $pendingToolResults = [];
            }

            if ('user' === $role) {
                $content = $msg['content'] ?? '';
                if (is_array($content)) {
                    $parts = [];
                    foreach ($content as $part) {
                        /** @var array<string, mixed> $part */
                        if (!is_array($part)) {
                            continue;
                        }
                        $partType = is_string($part['type'] ?? null) ? (string) $part['type'] : '';
                        if ('text' === $partType) {
                            $parts[] = ['type' => 'text', 'text' => is_scalar($part['text'] ?? null) ? (string) $part['text'] : ''];
                        } elseif ('image_url' === $partType) {
                            /** @var array<string, mixed> $imageUrl */
                            $imageUrl = is_array($part['image_url'] ?? null) ? $part['image_url'] : [];
                            $url = is_string($imageUrl['url'] ?? null) ? (string) $imageUrl['url'] : '';
                            if (str_starts_with($url, 'data:')) {
                                [$meta, $b64] = explode(',', $url, 2) + [1 => ''];
                                $mimeType = str_replace('data:', '', explode(';', $meta)[0]);
                                $parts[] = [
                                    'type' => 'image',
                                    'source' => [
                                        'type' => 'base64',
                                        'media_type' => $mimeType,
                                        'data' => $b64,
                                    ],
                                ];
                            } elseif ('' !== $url) {
                                $parts[] = [
                                    'type' => 'image',
                                    'source' => ['type' => 'url', 'url' => $url],
                                ];
                            }
                        }
                    }
                    $messages[] = ['role' => 'user', 'content' => $parts];
                } else {
                    $messages[] = ['role' => 'user', 'content' => is_scalar($content) ? (string) $content : (string) json_encode($content)];
                }
                continue;
            }

            if ('assistant' === $role) {
                // Preserve raw Anthropic blocks for multi-turn (thinking signatures requis)
                if (!empty($msg['_provider_raw_parts']) && is_array($msg['_provider_raw_parts'])) {
                    $messages[] = ['role' => 'assistant', 'content' => $msg['_provider_raw_parts']];
                    continue;
                }

                $parts = [];

                $textContent = $msg['content'] ?? '';
                if (is_string($textContent) && '' !== $textContent) {
                    $parts[] = ['type' => 'text', 'text' => $textContent];
                }

                /** @var array<int, array<string, mixed>> $toolCalls */
                $toolCalls = is_array($msg['tool_calls'] ?? null) ? $msg['tool_calls'] : [];
                foreach ($toolCalls as $tc) {
                    /** @var array<string, mixed> $tcFunction */
                    $tcFunction = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                    $argsStr = is_string($tcFunction['arguments'] ?? null) ? (string) $tcFunction['arguments'] : '{}';
                    $input = json_decode($argsStr, true);
                    if (!is_array($input)) {
                        $input = [];
                    }
                    if (!empty($tcFunction['name']) && is_string($tcFunction['name'])) {
                        $parts[] = [
                            'type' => 'tool_use',
                            'id' => is_string($tc['id'] ?? null) ? (string) $tc['id'] : 'toolu_unknown',
                            'name' => (string) $tcFunction['name'],
                            'input' => !empty($input) ? $input : new \stdClass(),
                        ];
                    }
                }

                if (!empty($parts)) {
                    $messages[] = ['role' => 'assistant', 'content' => $parts];
                }
            }
        }

        // Flush final des tool results restants
        if (!empty($pendingToolResults)) {
            $messages[] = ['role' => 'user', 'content' => $pendingToolResults];
        }

        return [$system, $messages];
    }

    /**
     * Construit le payload de requête Anthropic.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        string $model,
        array $messages,
        string $system,
        array $tools,
        ModelCapabilities $caps,
        bool $stream,
    ): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens ?? self::DEFAULT_MAX_TOKENS,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'stream' => $stream,
        ];

        if ('' !== $system) {
            $payload['system'] = $system;
        }

        if (!empty($this->stopSequences)) {
            $payload['stop_sequences'] = $this->stopSequences;
        }

        if (!empty($tools) && $caps->supportsFunctionCalling) {
            $payload['tools'] = $this->toAnthropicTools($tools);
        }

        if ($this->thinkingEnabled && $caps->supportsThinking) {
            $payload['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $this->thinkingBudget,
            ];
        }

        return $payload;
    }

    /**
     * Convertit les déclarations d'outils Synapse en format Anthropic.
     *
     * @param array<int, array<string, mixed>> $tools
     *
     * @return array<int, array<string, mixed>>
     */
    private function toAnthropicTools(array $tools): array
    {
        return array_map(fn (array $tool) => [
            'name' => $tool['name'] ?? '',
            'description' => $tool['description'] ?? '',
            'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()],
        ], $tools);
    }

    /**
     * Traite un événement SSE Anthropic et met à jour l'accumulateur de blocs.
     *
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $contentBlocks
     * @param array{prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int} $streamUsage
     *
     * @return array<string, mixed>|null
     */
    private function processStreamEvent(array $data, array &$contentBlocks, array &$streamUsage): ?array
    {
        $type = is_string($data['type'] ?? null) ? (string) $data['type'] : '';

        switch ($type) {
            case 'message_start':
                /** @var array<string, mixed> $message */
                $message = is_array($data['message'] ?? null) ? $data['message'] : [];
                /** @var array<string, mixed> $usage */
                $usage = is_array($message['usage'] ?? null) ? $message['usage'] : [];
                if (is_numeric($usage['input_tokens'] ?? null)) {
                    $streamUsage['prompt_tokens'] = (int) $usage['input_tokens'];
                }
                if (is_numeric($usage['output_tokens'] ?? null)) {
                    $streamUsage['completion_tokens'] = (int) $usage['output_tokens'];
                }

                return null;

            case 'content_block_start':
                $idx = is_numeric($data['index'] ?? null) ? (int) $data['index'] : 0;
                /** @var array<string, mixed> $block */
                $block = is_array($data['content_block'] ?? null) ? $data['content_block'] : [];
                $blockType = is_string($block['type'] ?? null) ? (string) $block['type'] : '';
                $contentBlocks[$idx] = [
                    'type' => $blockType,
                    'text' => '',
                    'thinking' => '',
                    'signature' => '',
                    'tool_use_id' => is_string($block['id'] ?? null) ? (string) $block['id'] : '',
                    'tool_use_name' => is_string($block['name'] ?? null) ? (string) $block['name'] : '',
                    'tool_use_input_raw' => '',
                ];

                return null;

            case 'content_block_delta':
                $idx = is_numeric($data['index'] ?? null) ? (int) $data['index'] : 0;
                /** @var array<string, mixed> $delta */
                $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                $deltaType = is_string($delta['type'] ?? null) ? (string) $delta['type'] : '';

                if (!isset($contentBlocks[$idx])) {
                    return null;
                }

                if ('text_delta' === $deltaType) {
                    $text = is_string($delta['text'] ?? null) ? (string) $delta['text'] : '';
                    if ('' === $text) {
                        return null;
                    }
                    $contentBlocks[$idx]['text'] .= $text;
                    $chunk = $this->emptyChunk();
                    $chunk['text'] = TextUtil::sanitizeUtf8($text);

                    return $chunk;
                }

                if ('thinking_delta' === $deltaType) {
                    $thinking = is_string($delta['thinking'] ?? null) ? (string) $delta['thinking'] : '';
                    if ('' === $thinking) {
                        return null;
                    }
                    $contentBlocks[$idx]['thinking'] .= $thinking;
                    $chunk = $this->emptyChunk();
                    $chunk['thinking'] = TextUtil::sanitizeUtf8($thinking);

                    return $chunk;
                }

                if ('signature_delta' === $deltaType) {
                    $sig = is_string($delta['signature'] ?? null) ? (string) $delta['signature'] : '';
                    $contentBlocks[$idx]['signature'] .= $sig;

                    return null;
                }

                if ('input_json_delta' === $deltaType) {
                    $partial = is_string($delta['partial_json'] ?? null) ? (string) $delta['partial_json'] : '';
                    $contentBlocks[$idx]['tool_use_input_raw'] .= $partial;

                    return null;
                }

                return null;

            case 'content_block_stop':
                $idx = is_numeric($data['index'] ?? null) ? (int) $data['index'] : 0;
                if (!isset($contentBlocks[$idx])) {
                    return null;
                }
                $block = $contentBlocks[$idx];
                if ('tool_use' === $block['type']) {
                    // Yield le tool call dès que son bloc est complet
                    $argsRaw = json_decode((string) $block['tool_use_input_raw'], true);
                    $args = is_array($argsRaw) ? $argsRaw : [];
                    $chunk = $this->emptyChunk();
                    $chunk['function_calls'] = [[
                        'id' => (string) $block['tool_use_id'],
                        'name' => (string) $block['tool_use_name'],
                        'args' => $args,
                    ]];

                    return $chunk;
                }

                return null;

            case 'message_delta':
                /** @var array<string, mixed> $delta */
                $delta = is_array($data['delta'] ?? null) ? $data['delta'] : [];
                /** @var array<string, mixed> $usage */
                $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
                if (is_numeric($usage['output_tokens'] ?? null)) {
                    $streamUsage['completion_tokens'] = (int) $usage['output_tokens'];
                }
                // stop_reason disponible dans $delta mais non utilisé ici
                unset($delta);

                return null;

            case 'message_stop':
            case 'ping':
            default:
                return null;
        }
    }

    /**
     * Extrait les tool calls pending de l'accumulateur (sécurité flush final).
     *
     * @param array<int, array<string, mixed>> $contentBlocks
     *
     * @return list<array{id: string, name: string, args: array<string, mixed>}>
     */
    private function extractPendingToolCalls(array $contentBlocks): array
    {
        // Les tool calls sont déjà yieldés sur content_block_stop — cette méthode
        // reste en sécurité si message_stop arrive sans content_block_stop.
        // Par défaut, aucun pending n'est retourné car le flush se fait déjà
        // au fil de l'eau. Ce point peut être durci si une trace montre un cas réel.
        return [];
    }

    /**
     * Normalise une réponse synchrone complète (non-streaming).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function normalizeCompletionResponse(array $data): array
    {
        $normalized = $this->emptyChunk();

        if (isset($data['usage']) && is_array($data['usage'])) {
            /** @var array<string, mixed> $u */
            $u = $data['usage'];
            $input = is_numeric($u['input_tokens'] ?? null) ? (int) $u['input_tokens'] : 0;
            $output = is_numeric($u['output_tokens'] ?? null) ? (int) $u['output_tokens'] : 0;
            $normalized['usage'] = [
                'prompt_tokens' => $input,
                'completion_tokens' => $output,
                'thinking_tokens' => 0, // Anthropic comptabilise le thinking dans output_tokens
                'total_tokens' => $input + $output,
            ];
        }

        /** @var array<int, array<string, mixed>> $content */
        $content = is_array($data['content'] ?? null) ? $data['content'] : [];

        $textParts = [];
        $thinkingParts = [];
        $rawPartsForHistory = [];

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }
            $blockType = is_string($block['type'] ?? null) ? (string) $block['type'] : '';

            if ('text' === $blockType) {
                $textParts[] = is_string($block['text'] ?? null) ? (string) $block['text'] : '';
                $rawPartsForHistory[] = $block;
            } elseif ('thinking' === $blockType) {
                $thinkingParts[] = is_string($block['thinking'] ?? null) ? (string) $block['thinking'] : '';
                $rawPartsForHistory[] = $block;
            } elseif ('tool_use' === $blockType) {
                /** @var array<string, mixed> $input */
                $input = is_array($block['input'] ?? null) ? $block['input'] : [];
                $normalized['function_calls'][] = [
                    'id' => is_string($block['id'] ?? null) ? (string) $block['id'] : 'toolu_unknown',
                    'name' => is_string($block['name'] ?? null) ? (string) $block['name'] : 'unknown',
                    'args' => $input,
                ];
                $rawPartsForHistory[] = $block;
            }
        }

        if (!empty($textParts)) {
            $normalized['text'] = TextUtil::sanitizeUtf8(implode('', $textParts));
        }
        if (!empty($thinkingParts)) {
            $normalized['thinking'] = TextUtil::sanitizeUtf8(implode('', $thinkingParts));
        }

        if (!empty($rawPartsForHistory)) {
            $normalized['_provider_raw_parts'] = $rawPartsForHistory;
        }

        return $normalized;
    }

    protected function getProviderLabel(): string
    {
        return 'Anthropic';
    }

    protected function parseErrorBody(string $errorBody, string $originalMessage): string
    {
        $errorData = json_decode($errorBody, true);
        if (is_array($errorData) && isset($errorData['error']) && is_array($errorData['error'])) {
            $error = $errorData['error'];
            if (isset($error['message']) && is_string($error['message'])) {
                return (string) $error['message'];
            }
        }

        return $originalMessage.' || Anthropic Raw Error: '.$errorBody;
    }

    /**
     * Applique la configuration dynamique depuis le ConfigProvider (DB).
     */
    private function applyDynamicConfig(): void
    {
        $config = $this->configProvider->getConfig();

        if ('' !== $config->model) {
            $this->model = $config->model;
        }

        $creds = $config->providerCredentials;
        if (!empty($creds)) {
            if (!empty($creds['api_key']) && is_string($creds['api_key'])) {
                $this->apiKey = (string) $creds['api_key'];
            }
            if (!empty($creds['endpoint']) && is_string($creds['endpoint'])) {
                $this->endpoint = (string) $creds['endpoint'];
            }
        }

        $gen = $config->generation;
        $this->temperature = $gen->temperature;
        $this->topP = $gen->topP;
        if (null !== $gen->maxOutputTokens) {
            $this->maxTokens = $gen->maxOutputTokens;
        }
        if ([] !== $gen->stopSequences) {
            $this->stopSequences = $gen->stopSequences;
        }

        $this->thinkingEnabled = $config->thinking->enabled;
        $this->thinkingBudget = $config->thinking->budget;
    }

    /**
     * Construit les headers HTTP pour l'API Anthropic.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ];
    }

    public function getCredentialFields(): array
    {
        return [
            'api_key' => [
                'label' => 'API Key',
                'type' => 'password',
                'help' => 'Clé API Anthropic (console.anthropic.com → Settings → API Keys).',
                'placeholder' => 'sk-ant-...',
                'required' => true,
            ],
            'endpoint' => [
                'label' => 'Endpoint URL',
                'type' => 'text',
                'help' => 'URL de base de l\'API. Laisser la valeur par défaut sauf cas particulier.',
                'value' => 'https://api.anthropic.com/v1',
                'required' => true,
            ],
        ];
    }

    public function validateCredentials(array $credentials): void
    {
        $apiKey = $credentials['api_key'] ?? '';
        $endpoint = $credentials['endpoint'] ?? 'https://api.anthropic.com/v1';

        if (empty($apiKey) || !is_string($apiKey)) {
            throw new \Exception('API Key manquante');
        }
        if (!is_string($endpoint)) {
            $endpoint = 'https://api.anthropic.com/v1';
        }

        try {
            // Appel minimal pour vérifier l'auth (et la route /messages).
            $response = $this->httpClient->request('POST', rtrim($endpoint, '/').'/messages', [
                'headers' => [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => self::ANTHROPIC_VERSION,
                    'content-type' => 'application/json',
                ],
                'json' => [
                    'model' => 'claude-haiku-4-5',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'hi']],
                ],
                'timeout' => 15,
            ]);

            $status = $response->getStatusCode();
            // 200 = OK. 400/404 = requête acceptée par l'auth mais payload/modèle invalide → credentials OK.
            // 401/403 = auth invalide → erreur.
            if (401 === $status || 403 === $status) {
                throw new \Exception('Authentification refusée (HTTP '.$status.') : '.$response->getContent(false));
            }
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Authentification refusée')) {
                throw $e;
            }
            // Autres exceptions HTTP : on essaie d'être tolérant — uniquement auth fail bloque.
            throw new \Exception('Impossible de se connecter à Anthropic: '.$e->getMessage());
        }
    }

    public function getDefaultLabel(): string
    {
        return 'Anthropic Claude';
    }

    public function getIcon(): string
    {
        return 'sparkles';
    }

    public function getDefaultCurrency(): string
    {
        return 'USD';
    }

    public function getProviderOptionsSchema(): array
    {
        return ['fields' => [
            [
                'name' => 'thinking.enabled',
                'type' => 'checkbox',
                'label' => 'Activer le thinking étendu',
                'help' => 'Raisonnement approfondi avec budget de tokens dédié',
                'capability' => 'supportsThinking',
            ],
            [
                'name' => 'thinking.budget',
                'type' => 'number',
                'label' => 'Budget de réflexion (tokens)',
                'help' => '1024–64000 tokens alloués au raisonnement',
                'min' => 1024,
                'max' => 64000,
                'defaultValue' => 1024,
                'dependsOn' => 'thinking.enabled',
            ],
        ]];
    }

    public function validateProviderOptions(array $options, ModelCapabilities $caps): array
    {
        if (!$caps->supportsThinking) {
            unset($options['thinking']);
        }

        if ($caps->supportsThinking && isset($options['thinking']) && is_array($options['thinking']) && !empty($options['thinking']['budget'])) {
            $budget = is_numeric($options['thinking']['budget']) ? (int) $options['thinking']['budget'] : 0;
            if ($budget < 1024 || $budget > 64000) {
                $options['thinking']['budget'] = 1024;
            }
        }

        unset($options['safety_settings']);

        return $options;
    }

    /**
     * Évaluation RGPD pour Anthropic.
     *
     * Anthropic PBC est une société américaine (San Francisco), soumise au CLOUD Act.
     * Avantages : DPA disponible, pas d'entraînement sur les données API par défaut,
     * conformité SOC 2 Type II. Limites : infrastructure hébergée aux US, pas de
     * garantie de résidence UE.
     *
     * Statut : 'risk' — société non-UE avec garanties contractuelles mais transfert
     * de données hors UE.
     */
    public function getRgpdInfo(array $providerCredentials, array $presetOptions, string $model): RgpdInfo
    {
        $caps = $this->capabilityRegistry->getCapabilities($model);
        if (!$caps->isRgpdSafe()) {
            return new RgpdInfo(
                status: 'danger',
                label: 'RGPD',
                explanation: sprintf(
                    'Le modèle %s présente un risque RGPD inhérent. Ce modèle ne doit pas être utilisé '
                    .'pour traiter des données personnelles.',
                    $model
                ),
            );
        }

        return new RgpdInfo(
            status: 'risk',
            label: 'RGPD',
            explanation: 'Anthropic PBC est une société américaine (soumise au CLOUD Act). '
                .'Pas d\'entraînement sur les données API par défaut, DPA disponible, SOC 2 Type II. '
                .'Hébergement des données aux États-Unis — évitez les données personnelles sensibles '
                .'sans analyse préalable de conformité.',
        );
    }
}
