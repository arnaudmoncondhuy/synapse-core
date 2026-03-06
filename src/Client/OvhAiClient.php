<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Client;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EmbeddingClientInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmAuthenticationException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmRateLimitException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmServiceUnavailableException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour OVH AI Endpoints (100% compatible API OpenAI).
 *
 * Utilise le format OpenAI canonical en interne (aucune conversion nécessaire).
 * Les messages reçus sont déjà au format OpenAI, envoyés directement à l'API.
 *
 * Format entrant (OpenAI canonical) :
 *   ['role' => 'user'|'assistant'|'system'|'tool', 'content' => string, 'tool_calls' => [...], 'tool_call_id' => ...]
 *
 * Format sortant (chunks normalisés) :
 *   ['text' => string|null, 'thinking' => null, 'function_calls' => [...], ...]
 *
 * Endpoint   : https://oai.endpoints.kepler.ai.cloud.ovh.net/v1
 * Auth       : Bearer {api_key}
 * Streaming  : SSE (data: {...}\n, terminé par data: [DONE])
 * Tool calls : format OpenAI (tool_calls / role tool)
 */
class OvhAiClient implements LlmClientInterface, EmbeddingClientInterface
{
    private string $model = 'Gpt-oss-20b';
    private string $apiKey = '';
    private string $endpoint = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';
    private float $temperature = 1.0;
    private float $topP = 0.95;
    private ?int $maxTokens = null;
    /** @var string[] */
    private array $stopSequences = [];
    private bool $thinkingEnabled = false;
    private ?int $thinkingBudget = null;
    private string $reasoningEffort = 'high';  // high, medium, low, minimal

    public function __construct(
        private HttpClientInterface $httpClient,
        private ConfigProviderInterface $configProvider,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {}

    public function getProviderName(): string
    {
        return 'ovh';
    }

    /**
     * Génère du contenu en mode streaming (SSE).
     * Yield des chunks normalisés au format Synapse.
     *
     * Les messages sont déjà au format OpenAI canonical, envoyés directement.
     */
    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $messages = $contents;  // Already OpenAI format, passthrough
        $payload = $this->buildPayload($effectiveModel, $messages, $tools, $caps, true);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $debugOut['actual_request_params'] = [
            'model' => $effectiveModel,
            'provider' => 'ovh',
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => null,  // OVH n'expose pas topK
            'max_output_tokens' => $this->maxTokens,
            'thinking_enabled' => $this->thinkingEnabled,
            'thinking_budget' => $this->thinkingBudget,
            'reasoning_effort' => $this->thinkingEnabled ? $this->reasoningEffort : null,
            'safety_enabled' => false,  // OVH n'a pas de sécurité native
            'tools_sent' => !empty($tools) && $caps->functionCalling,
            'system_prompt_sent' => $caps->systemPrompt && !empty($contents) && ($contents[0]['role'] ?? '') === 'system',
            'context_caching' => false,  // OVH n'a pas de context caching
        ];
        $debugOut['raw_request_body'] = \ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil::sanitizeArrayUtf8($payload);

        try {
            $response = $this->httpClient->request('POST', rtrim((string) $this->endpoint, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 300,
                'buffer' => false,
            ]);

            // Accumulate tool call arguments across chunks (tool calls are streamed incrementally)
            /** @var array<int, array{id: string, name: string, args: string}> $toolCallsAccumulator */
            $toolCallsAccumulator = [];
            $buffer = '';
            $rawApiChunks = []; // Capturer tous les chunks bruts de l'API pour le debug
            $streamingComplete = false;

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($streamingComplete) {
                    break;
                }

                try {
                    $buffer .= $chunk->getContent();
                } catch (\Throwable $e) {
                    $this->handleException($e);

                    return;
                }

                // Process complete SSE lines from buffer
                while (($nlPos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $nlPos);
                    $buffer = substr($buffer, $nlPos + 1);
                    $line = rtrim($line, "\r");

                    if ('data: [DONE]' === $line) {
                        // Safety net: flush remaining accumulated tool calls if any
                        if (!empty($toolCallsAccumulator)) {
                            /** @var array<int, array{id: string, name: string, args: string}> $toolCallsAccumulator */
                            yield $this->buildToolCallChunk($toolCallsAccumulator);
                        }
                        $streamingComplete = true;
                        break;
                    }

                    if ('' === $line || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonStr = substr($line, 6); // Remove 'data: '
                    $data = json_decode($jsonStr, true);
                    if (!is_array($data)) {
                        continue;
                    }

                    // Capturer les chunks bruts AVANT normalisation (vrai debug)
                    $rawApiChunks[] = $data;

                    /** @var array<string, mixed> $rawChunk */
                    $rawChunk = $data;
                    $result = $this->processChunk($rawChunk, $toolCallsAccumulator);
                    if (null !== $result) {
                        yield $result;
                    }
                }
            }

            // Handle any remaining buffer content (edge case)
            $remaining = trim($buffer);
            if ('' !== $remaining && str_starts_with($remaining, 'data: ') && 'data: [DONE]' !== $remaining) {
                $jsonStr = substr($remaining, 6);
                $data = json_decode($jsonStr, true);
                if (is_array($data)) {
                    // Capturer le dernier chunk brut aussi
                    $rawApiChunks[] = $data;

                    /** @var array<string, mixed> $rawChunk2 */
                    $rawChunk2 = $data;
                    $result = $this->processChunk($rawChunk2, $toolCallsAccumulator);
                    if (null !== $result) {
                        yield $result;
                    }
                }
            }

            // Passer les chunks bruts de l'API au debug (VRAI brut, avant normalisation)
            $debugOut['raw_api_chunks'] = \ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil::sanitizeArrayUtf8($rawApiChunks);
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Génère du contenu en mode synchrone (non-streaming).
     *
     * Les messages sont déjà au format OpenAI canonical, envoyés directement.
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

        $messages = $contents;  // Already OpenAI format, passthrough
        $payload = $this->buildPayload($effectiveModel, $messages, $tools, $caps, false);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $debugOut['actual_request_params'] = [
            'model' => $effectiveModel,
            'provider' => 'ovh',
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => null,  // OVH n'expose pas topK
            'max_output_tokens' => $this->maxTokens,
            'thinking_enabled' => $this->thinkingEnabled,
            'thinking_budget' => $this->thinkingBudget,
            'reasoning_effort' => $this->thinkingEnabled ? $this->reasoningEffort : null,
            'safety_enabled' => false,  // OVH n'a pas de sécurité native
            'tools_sent' => !empty($tools) && $caps->functionCalling,
            'system_prompt_sent' => $caps->systemPrompt && !empty($contents) && ($contents[0]['role'] ?? '') === 'system',
            'context_caching' => false,  // OVH n'a pas de context caching
        ];
        $debugOut['raw_request_body'] = $payload;

        try {
            $response = $this->httpClient->request('POST', rtrim((string) $this->endpoint, '/') . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 300,
            ]);

            $data = $response->toArray();

            // Passer la réponse brute de l'API au debug (VRAI brut, avant normalisation)
            $debugOut['raw_api_response'] = $data;

            return $this->normalizeCompletionResponse($data);
        } catch (\Throwable $e) {
            $this->handleException($e);

            return $this->emptyChunk();
        }
    }

    /**
     * Génère des embeddings vectoriels pour un ou plusieurs textes d'entrée.
     * Compatible avec l'endpoint /v1/embeddings de type OpenAI (comme OVH).
     */
    public function generateEmbeddings(string|array $input, ?string $model = null, array $options = []): array
    {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;

        $payload = [
            'model' => $effectiveModel,
            'input' => $input,
        ];

        try {
            $response = $this->httpClient->request('POST', rtrim((string) $this->endpoint, '/') . '/embeddings', [
                'headers' => [
                    'Authorization' => 'Bearer ' . (string) $this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            $embeddings = [];
            if (isset($data['data']) && is_array($data['data'])) {
                // OpenAI returns data sorted by index, but it's good practice to ensure it
                /** @var array<int, array<string, mixed>> $dataList */
                $dataList = $data['data'];
                usort($dataList, function (array $a, array $b) {
                    $idxA = isset($a['index']) && is_numeric($a['index']) ? (int) $a['index'] : 0;
                    $idxB = isset($b['index']) && is_numeric($b['index']) ? (int) $b['index'] : 0;
                    return $idxA <=> $idxB;
                });

                foreach ($dataList as $item) {
                    if (is_array($item) && isset($item['embedding']) && is_array($item['embedding'])) {
                        /** @var list<float> $vals */
                        $vals = $item['embedding'];
                        $embeddings[] = $vals;
                    }
                }
            }

            $usage = [
                'prompt_tokens' => is_numeric($data['usage']['prompt_tokens'] ?? null) ? (int) $data['usage']['prompt_tokens'] : 0,
                'total_tokens' => is_numeric($data['usage']['total_tokens'] ?? null) ? (int) $data['usage']['total_tokens'] : 0,
            ];

            return [
                'embeddings' => $embeddings,
                'usage' => $usage,
            ];
        } catch (\Throwable $e) {
            $this->handleException($e);

            return ['embeddings' => [], 'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0]];
        }
    }

    /**
     * Traite un chunk SSE et met à jour l'accumulateur de tool calls.
     * Retourne un chunk normalisé, ou null si le chunk ne contient rien d'utile.
     *
     * @param array<string, mixed> $data
     * @param array<mixed>         $toolCallsAccumulator
     *
     * @return array<string, mixed>|null
     */
    private function processChunk(array $data, array &$toolCallsAccumulator): ?array
    {
        $normalized = $this->emptyChunk();

        // Usage metadata (usually in the last chunk with stream_options.include_usage)
        if (isset($data['usage']) && is_array($data['usage'])) {
            $u = $data['usage'];
            // OVH peut fournir reasoning_tokens sous completion_tokens_details (imbriqué)
            $reasoningTokens = 0;
            if (isset($u['completion_tokens_details']) && is_array($u['completion_tokens_details'])) {
                $reasoningTokens = $u['completion_tokens_details']['reasoning_tokens'] ?? 0;
            }
            $normalized['usage'] = [
                'prompt_tokens' => $u['prompt_tokens'] ?? 0,
                'completion_tokens' => $u['completion_tokens'] ?? 0,
                'thinking_tokens' => $reasoningTokens,
                'total_tokens' => $u['total_tokens'] ?? 0,
            ];
        }

        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        $choice = is_array($choices[0] ?? null) ? $choices[0] : null;
        if (null === $choice) {
            // Only yield if we have usage data
            return !empty($normalized['usage']) ? $normalized : null;
        }

        $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
        $finishReason = is_string($choice['finish_reason'] ?? null) ? (string) $choice['finish_reason'] : null;

        // Text content
        if (isset($delta['content']) && is_string($delta['content']) && '' !== $delta['content']) {
            $normalized['text'] = (string) $delta['content'];
        }

        // Reasoning/Thinking content (OpenAI compatible format)
        // OVH may return reasoning in 'reasoning' or 'reasoning_content' fields
        if (isset($delta['reasoning']) && is_string($delta['reasoning']) && '' !== $delta['reasoning']) {
            $normalized['thinking'] = (string) $delta['reasoning'];
        } elseif (isset($delta['reasoning_content']) && is_string($delta['reasoning_content']) && '' !== $delta['reasoning_content']) {
            $normalized['thinking'] = (string) $delta['reasoning_content'];
        }

        // Tool calls (streamed incrementally — name in first chunk, args accumulated)
        if (isset($delta['tool_calls']) && is_array($delta['tool_calls'])) {
            foreach ($delta['tool_calls'] as $tc) {

                /** @var array<string, mixed> $tc */
                if (!is_array($tc)) {
                    continue;
                }
                $idxMixed = $tc['index'] ?? 0;
                $idx = is_numeric($idxMixed) ? (int) $idxMixed : 0;
                if (!isset($toolCallsAccumulator[$idx])) {
                    $toolCallsAccumulator[$idx] = ['id' => '', 'name' => '', 'args' => ''];
                }
                if (!empty($tc['id']) && is_string($tc['id'])) {
                    $toolCallsAccumulator[$idx]['id'] = (string) $tc['id'];
                }
                if (is_array($tc['function'] ?? null) && !empty($tc['function']['name'] ?? '')) {
                    /** @var array<string, mixed> $func */
                    $func = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                    $toolCallsAccumulator[$idx]['name'] = is_string($func['name'] ?? null) ? (string) $func['name'] : '';
                }
                if (is_array($tc['function'] ?? null) && isset($tc['function']['arguments']) && is_scalar($tc['function']['arguments'])) {
                    $toolCallsAccumulator[$idx]['args'] .= (string) $tc['function']['arguments'];
                }
            }
        }

        // When finish_reason is 'tool_calls', all tool call chunks have been received
        if ('tool_calls' === $finishReason && !empty($toolCallsAccumulator)) {
            /** @var array<int, array{id: string, name: string, args: string}> $toolCallsAccumulator */
            $toolChunk = $this->buildToolCallChunk($toolCallsAccumulator);
            // Merge usage if present in the same chunk
            if (!empty($normalized['usage'])) {
                $toolChunk['usage'] = $normalized['usage'];
            }

            return $toolChunk;
        }

        // Skip truly empty chunks (no text, no thinking, no usage, no tool data)
        $hasContent = null !== $normalized['text']
            || null !== $normalized['thinking']
            || !empty($normalized['usage']);

        return $hasContent ? $normalized : null;
    }

    /**
     * Construit un chunk normalisé à partir des tool calls accumulés.
     * Vide l'accumulateur.
     *
     * @param array<int, array{id: string, name: string, args: string}> $toolCallsAccumulator
     *
     * @return array<string, mixed>
     */
    /** @param array<int, array{id: string, name: string, args: string}> $toolCallsAccumulator */
    /** @param array<int, array{id: string, name: string, args: string}> $toolCallsAccumulator
     *  @return array<string, mixed>
     */
    private function buildToolCallChunk(array &$toolCallsAccumulator): array
    {
        $chunk = $this->emptyChunk();

        ksort($toolCallsAccumulator);
        foreach ($toolCallsAccumulator as $tc) {
            /** @var array{id: string, name: string, args: string} $tcArray */
            $tcArray = $tc;
            $argsRaw = json_decode((string) $tcArray['args'], true);
            $args = is_array($argsRaw) ? $argsRaw : [];
            $chunk['function_calls'][] = [
                'id' => (string) $tcArray['id'],
                'name' => (string) $tcArray['name'],
                'args' => $args,
            ];
        }

        $toolCallsAccumulator = [];

        return $chunk;
    }

    /**
     * Convertit les déclarations d'outils Synapse en format OpenAI.
     *
     * @param array<int, array<string, mixed>> $tools
     *
     * @return array<int, array<string, mixed>>
     */
    private function toOpenAiTools(array $tools): array
    {
        return array_map(fn(array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'],
            ],
        ], $tools);
    }

    /**
     * Construit le payload de requête OpenAI.
     *
     * @param array<int, array<string, mixed>>                             $messages
     * @param array<int, array<string, mixed>>                             $tools
     * @param \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities $caps
     *
     * @return array<string, mixed>
     */
    private function buildPayload(string $model, array $messages, array $tools, \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities $caps, bool $stream): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'stream' => $stream,
        ];

        if ($stream) {
            $payload['stream_options'] = ['include_usage' => true];
        }

        if (null !== $this->maxTokens) {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if (!empty($this->stopSequences)) {
            $payload['stop'] = $this->stopSequences;
        }

        if (!empty($tools) && $caps->functionCalling) {
            $payload['tools'] = $this->toOpenAiTools($tools);
        }

        // Ajouter la réflexion/reasoning si activée (paramètre OVH: reasoning_effort)
        // L'API rejette le paramètre si le modèle n'a pas de capacités de réflexion (400 Bad Request)
        if ($this->thinkingEnabled && $caps->thinking) {
            // Les valeurs possibles sont: "high", "medium", "low", "minimal"
            $payload['reasoning_effort'] = $this->reasoningEffort;
        }

        return $payload;
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
            $u = $data['usage'];
            // OVH peut fournir reasoning_tokens sous completion_tokens_details (imbriqué)
            $reasoningTokens = 0;
            if (isset($u['completion_tokens_details']) && is_array($u['completion_tokens_details'])) {
                $reasoningTokens = $u['completion_tokens_details']['reasoning_tokens'] ?? 0;
            }
            $normalized['usage'] = [
                'prompt_tokens' => $u['prompt_tokens'] ?? 0,
                'completion_tokens' => $u['completion_tokens'] ?? 0,
                'thinking_tokens' => $reasoningTokens,
                'total_tokens' => $u['total_tokens'] ?? 0,
            ];
        }

        $choices = is_array($data['choices'] ?? null) ? $data['choices'] : [];
        $choice = is_array($choices[0] ?? null) ? $choices[0] : null;
        if (null === $choice) {
            return $normalized;
        }

        /** @var array<string, mixed> $message */
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];

        if (!empty($message['content']) && is_string($message['content'])) {
            $normalized['text'] = (string) $message['content'];
        }

        // OVH retourne le reasoning dans message.reasoning_content (mode synchrone)
        if (!empty($message['reasoning_content']) && is_string($message['reasoning_content'])) {
            $normalized['thinking'] = (string) $message['reasoning_content'];
        }

        if (!empty($message['tool_calls']) && is_array($message['tool_calls'])) {
            foreach ($message['tool_calls'] as $tc) {

                /** @var array<string, mixed> $tc */

                $function = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                $argsStr = is_string($function['arguments'] ?? null) ? (string) $function['arguments'] : '{}';
                $argsRaw2 = json_decode((string) $argsStr, true);
                $args = is_array($argsRaw2) ? $argsRaw2 : [];
                if (!empty($function['name'])) {
                    /** @var array<string, mixed> $funcData */
                    $funcData = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                    $normalized['function_calls'][] = [
                        'name' => is_string($funcData['name'] ?? null) ? (string) $funcData['name'] : 'unknown',
                        'args' => is_array($args) ? $args : [],
                    ];
                }
            }
        }

        return $normalized;
    }

    /**
     * Retourne un chunk normalisé vide (toutes les valeurs par défaut).
     *
     * @return array<string, mixed>
     */
    private function emptyChunk(): array
    {
        return [
            'text' => null,
            'thinking' => null,
            'function_calls' => [],
            'usage' => [],
            'safety_ratings' => [],
            'blocked' => false,
            'blocked_reason' => null,
        ];
    }

    /**
     * Applique la configuration dynamique depuis le ConfigProvider (DB).
     *
     * Credentials provider (api_key, endpoint) lus depuis provider_credentials.
     * Les paramètres OVH-incompatibles (top_k, thinking, safety) sont ignorés.
     */
    private function applyDynamicConfig(): void
    {
        $config = $this->configProvider->getConfig();

        if (!empty($config['model'])) {
            $this->model = is_string($config['model'] ?? null) ? (string) $config['model'] : '';
        }

        // Provider credentials (SynapseProvider en DB)
        $creds = is_array($config['provider_credentials'] ?? null) ? $config['provider_credentials'] : [];

        if (!empty($creds)) {
            if (!empty($creds['api_key']) && is_string($creds['api_key'])) {
                $this->apiKey = (string) $creds['api_key'];
            }
            if (!empty($creds['endpoint']) && is_string($creds['endpoint'])) {
                $this->endpoint = (string) $creds['endpoint'];
            }
        }

        // Generation Config
        if (isset($config['generation_config']) && is_array($config['generation_config'])) {
            $gen = $config['generation_config'];
            if (isset($gen['temperature']) && is_numeric($gen['temperature'])) { $this->temperature = (float) $gen['temperature']; }
            if (isset($gen['top_p']) && is_numeric($gen['top_p'])) { $this->topP = (float) $gen['top_p']; }
            $this->maxTokens = isset($gen['max_output_tokens']) ? (int) $gen['max_output_tokens'] : $this->maxTokens;
            if (isset($gen['stop_sequences']) && is_array($gen['stop_sequences'])) {
                /** @var array<string> $stopSeqs */
                $stopSeqs = $gen['stop_sequences'];
                $this->stopSequences = $stopSeqs;
            }
        }

        // Réflexion/Thinking (stocké séparément dans config)
        if (isset($config['thinking']) && is_array($config['thinking'])) {
            $thinking = $config['thinking'];
            $this->thinkingEnabled = (bool) ($thinking['enabled'] ?? false);
            $this->thinkingBudget = isset($thinking['budget']) ? (int) $thinking['budget'] : null;
            $this->reasoningEffort = (string) ($thinking['reasoning_effort'] ?? 'high');
        }
    }

    public function getCredentialFields(): array
    {
        return [
            'api_key' => [
                'label' => 'API Key (Bearer Token)',
                'type' => 'password',
                'help' => 'Token d\'authentification OVH AI Endpoints.',
                'placeholder' => 'ovh_...',
                'required' => true,
            ],
            'endpoint' => [
                'label' => 'Endpoint URL',
                'type' => 'text',
                'help' => 'URL de base de l\'API. Laisser la valeur par défaut sauf cas particulier.',
                'value' => 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1',
                'required' => true,
            ],
        ];
    }

    public function validateCredentials(array $credentials): void
    {
        $apiKey = $credentials['api_key'] ?? '';
        $endpoint = $credentials['endpoint'] ?? 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';

        if (empty($apiKey)) {
            throw new \Exception('API Key manquante');
        }

        // Test de connexion: faire un appel à la liste des modèles (gratuit)
        try {
            /** @var string $apiKeyStr */
            $apiKeyStr = $apiKey;
            /** @var string $endpointStr */
            $endpointStr = $endpoint;
            $response = $this->httpClient->request('GET', rtrim($endpointStr, '/') . '/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKeyStr,
                    'Accept' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            if (200 !== $response->getStatusCode()) {
                throw new \Exception('Erreur HTTP ' . $response->getStatusCode() . ': ' . $response->getContent(false));
            }
        } catch (\Exception $e) {
            throw new \Exception('Impossible de se connecter à OVH: ' . $e->getMessage());
        }
    }

    public function getDefaultLabel(): string
    {
        return 'OVH AI Endpoints';
    }

    private function handleException(\Throwable $e): void
    {
        $message = $e->getMessage();
        $statusCode = null;

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getResponse()->getStatusCode();
            try {
                $errorBody = $e->getResponse()->getContent(false);
                $errorData = json_decode($errorBody, true);
                if (is_array($errorData) && isset($errorData['message'])) {
                    $message = (string) $errorData['message'];
                } else {
                    $message .= ' || OVH Raw Error: ' . $errorBody;
                }
            } catch (\Throwable) {
            }
        }

        $fullMsg = 'OVH AI API Error: ' . ((string) $message);

        throw match ($statusCode) {
            401, 403 => new LlmAuthenticationException($fullMsg, 0, $e),
            429 => new LlmRateLimitException($fullMsg, 0, $e),
            500, 503 => new LlmServiceUnavailableException($fullMsg, 0, $e),
            default => (str_contains(strtolower((string) $message), 'quota') ? new LlmQuotaException($fullMsg, 0, $e) : new LlmException($fullMsg, 0, $e)),
        };
    }
}
