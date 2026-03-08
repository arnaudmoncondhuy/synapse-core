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
use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP de bas niveau pour l'API Google Gemini via Vertex AI.
 *
 * Credentials (project_id, region, service_account_json) chargés dynamiquement
 * depuis SynapseProvider en DB — aucune valeur YAML requise après l'installation.
 */
class GeminiClient implements LlmClientInterface, EmbeddingClientInterface
{
    private const VERTEX_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';
    private const VERTEX_STREAM_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:streamGenerateContent';
    private const VERTEX_EMBEDDING_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict';

    // ── Config runtime (chargée depuis DB via applyDynamicConfig) ────────────
    private string $model = 'gemini-2.5-flash';
    private string $vertexProjectId = '';
    private string $vertexRegion = 'europe-west1';
    private bool $thinkingEnabled = true;
    private int $thinkingBudget = 1024;
    private bool $safetySettingsEnabled = false;
    private string $safetyDefaultThreshold = 'BLOCK_MEDIUM_AND_ABOVE';
    /** @var array<string, string> */
    private array $safetyThresholds = [];
    private float $generationTemperature = 1.0;
    private float $generationTopP = 0.95;
    private int $generationTopK = 40;
    private ?int $generationMaxOutputTokens = null;
    /** @var string[] */
    private array $generationStopSequences = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private GeminiAuthService $geminiAuthService,
        private ConfigProviderInterface $configProvider,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {
    }

    public function getProviderName(): string
    {
        return 'gemini';
    }

    /**
     * Génère du contenu via Vertex AI (mode synchrone).
     * Retourne un chunk normalisé au format Synapse.
     *
     * Les messages sont au format OpenAI canonical (contents contient déjà le message système en tête).
     *
     * @throws \RuntimeException En cas d'erreur API Vertex AI
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
        $url = $this->buildVertexUrl(self::VERTEX_URL, $effectiveModel);
        /** @var array<string, mixed>|null $thinkingOverride */
        $thinkingOverride = is_array($options['thinking'] ?? null) ? $options['thinking'] : null;
        $payload = $this->buildPayload($contents, $tools, $effectiveModel, $thinkingOverride);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);
        $debugOut['actual_request_params'] = [
            'model' => $effectiveModel,
            'provider' => 'gemini',
            'temperature' => $this->generationTemperature,
            'top_p' => $this->generationTopP,
            'top_k' => $caps->supportsTopK ? $this->generationTopK : null,
            'max_output_tokens' => $this->generationMaxOutputTokens,
            'thinking_enabled' => $this->thinkingEnabled && $caps->supportsThinking,
            'thinking_budget' => ($this->thinkingEnabled && $caps->supportsThinking) ? $this->thinkingBudget : null,
            'safety_enabled' => $this->safetySettingsEnabled,
            'tools_sent' => !empty($tools) && $caps->supportsFunctionCalling,
            'system_prompt_sent' => !empty($contents) && ($contents[0]['role'] ?? '') === 'system',
        ];
        $debugOut['raw_request_body'] = TextUtil::sanitizeArrayUtf8($payload);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $this->buildVertexHeaders(),
                'timeout' => 300,
            ]);

            $data = $response->toArray();
            // Passer la réponse brute de l'API au debug (VRAI brut, avant normalisation)
            $debugOut['raw_api_response'] = $data;

            return $this->normalizeChunk($data);
        } catch (\Throwable $e) {
            $this->handleException($e);

            return $this->emptyChunk();
        }
    }

    /**
     * Génère des embeddings vectoriels pour un ou plusieurs textes d'entrée.
     * Utilise le endpoint Vertex AI model.predict avec un format d'instance spécifique.
     */
    public function generateEmbeddings(string|array $input, ?string $model = null, array $options = []): array
    {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $url = $this->buildVertexUrl(self::VERTEX_EMBEDDING_URL, $effectiveModel);

        $inputs = is_array($input) ? $input : [$input];
        $instances = [];

        foreach ($inputs as $text) {
            $instances[] = [
                'content' => $text,
            ];
        }

        $payload = [
            'instances' => $instances,
        ];

        // Intégrer la dimension de sortie si requise
        if (isset($options['output_dimensionality'])) {
            $payload['parameters'] = [
                'outputDimensionality' => is_numeric($options['output_dimensionality'] ?? null) ? (int) $options['output_dimensionality'] : 768,
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $this->buildVertexHeaders(),
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            $embeddings = [];
            if (isset($data['predictions']) && is_array($data['predictions'])) {
                foreach ($data['predictions'] as $prediction) {
                    if (is_array($prediction)) {
                        $embData = $prediction['embeddings'] ?? null;
                        if (is_array($embData)) {
                            $vals = $embData['values'] ?? null;
                            if (is_array($vals)) {
                                /** @var list<float> $valsFloat */
                                $valsFloat = $vals;
                                $embeddings[] = $valsFloat;
                            }
                        }
                    }
                }
            }

            // Vertex AI embeddings API often returns billableCharacterCount instead of tokens
            // Approximation: 1 token ~= 4 chars pour l'anglais/français
            $billableChars = $data['metadata']['billableCharacterCount'] ?? 0;
            $totalTokens = (int) ceil($billableChars / 4.0);

            $usage = [
                'prompt_tokens' => $totalTokens,
                'total_tokens' => $totalTokens,
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
     * Génère du contenu via Vertex AI (mode streaming).
     * Yield des chunks normalisés au format Synapse.
     *
     * Les messages sont au format OpenAI canonical (contents contient déjà le message système en tête).
     *
     * @param array<int, array<string, mixed>> $contents
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed> $debugOut
     *
     * @throws \RuntimeException En cas d'erreur API Vertex AI
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator {
        $this->applyDynamicConfig();

        $effectiveModel = $model ?? $this->model;
        $url = $this->buildVertexUrl(self::VERTEX_STREAM_URL, $effectiveModel);
        $payload = $this->buildPayload($contents, $tools, $effectiveModel, null);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);
        $debugOut['actual_request_params'] = [
            'model' => $effectiveModel,
            'provider' => 'gemini',
            'temperature' => $this->generationTemperature,
            'top_p' => $this->generationTopP,
            'top_k' => $caps->supportsTopK ? $this->generationTopK : null,
            'max_output_tokens' => $this->generationMaxOutputTokens,
            'thinking_enabled' => $this->thinkingEnabled && $caps->supportsThinking,
            'thinking_budget' => ($this->thinkingEnabled && $caps->supportsThinking) ? $this->thinkingBudget : null,
            'safety_enabled' => $this->safetySettingsEnabled,
            'tools_sent' => !empty($tools) && $caps->supportsFunctionCalling,
            'system_prompt_sent' => !empty($contents) && ($contents[0]['role'] ?? '') === 'system',
        ];
        $debugOut['raw_request_body'] = TextUtil::sanitizeArrayUtf8($payload);

        $rawApiChunks = [];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json' => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $this->buildVertexHeaders(),
                'timeout' => 300,
            ]);

            $buffer = '';

            foreach ($this->httpClient->stream($response) as $chunk) {
                try {
                    $content = $chunk->getContent();
                } catch (\Throwable $e) {
                    $this->handleException($e);

                    return;
                }

                $buffer .= $content;

                // Parsing JSON Stream : format Vertex [ {obj1}, {obj2}, ... ]
                while (true) {
                    if (empty($buffer)) {
                        break;
                    }

                    $buffer = ltrim($buffer, " \t\n\r,");

                    if (str_starts_with($buffer, '[')) {
                        $buffer = substr($buffer, 1);
                        continue;
                    }
                    if (str_starts_with($buffer, ']')) {
                        $buffer = substr($buffer, 1);
                        continue;
                    }

                    if (empty($buffer)) {
                        break;
                    }

                    if (!str_starts_with($buffer, '{')) {
                        break;
                    }

                    $objEnd = $this->findObjectEnd($buffer);

                    if (null === $objEnd) {
                        break;
                    }

                    $jsonStr = substr($buffer, 0, $objEnd + 1);
                    $jsonData = json_decode($jsonStr, true);

                    if (JSON_ERROR_NONE === json_last_error() && is_array($jsonData)) {
                        // Capture le chunk brut AVANT normalisation (pour debug)
                        $rawApiChunks[] = $jsonData;
                        /** @var array<string, mixed> $rawChunk */
                        $rawChunk = $jsonData;
                        yield $this->normalizeChunk($rawChunk);
                        $buffer = substr($buffer, $objEnd + 1);
                    } else {
                        $buffer = substr($buffer, 1);
                    }
                }
            }

            // Sauvegarder les chunks bruts pour le debug
            if (!empty($rawApiChunks)) {
                $debugOut['raw_api_chunks'] = TextUtil::sanitizeArrayUtf8($rawApiChunks);
            }
        } catch (\Throwable $e) {
            $this->handleException($e);
        }
    }

    /**
     * Normalise un chunk brut Gemini vers le format Synapse canonical.
     *
     * @param array<string, mixed> $rawChunk
     *
     * @return array<string, mixed>
     */
    private function normalizeChunk(array $rawChunk): array
    {
        $normalized = $this->emptyChunk();

        if (isset($rawChunk['usageMetadata'])) {
            /** @var array<string, mixed> $u */
            $u = is_array($rawChunk['usageMetadata']) ? $rawChunk['usageMetadata'] : [];
            $normalized['usage'] = [
                'prompt_tokens' => is_numeric($u['promptTokenCount'] ?? null) ? (int) $u['promptTokenCount'] : 0,
                'completion_tokens' => is_numeric($u['candidatesTokenCount'] ?? null) ? (int) $u['candidatesTokenCount'] : 0,
                'thinking_tokens' => is_numeric($u['thoughtsTokenCount'] ?? null) ? (int) $u['thoughtsTokenCount'] : 0,
                'total_tokens' => is_numeric($u['totalTokenCount'] ?? null) ? (int) $u['totalTokenCount'] : 0,
            ];
        }

        /** @var array<string, mixed> $candidate */
        $candidate = is_array($rawChunk['candidates'] ?? null) ? ($rawChunk['candidates'][0] ?? []) : [];
        if (!is_array($candidate)) {
            $candidate = [];
        }

        if (isset($candidate['safetyRatings']) && is_array($candidate['safetyRatings'])) {
            $normalized['safety_ratings'] = $candidate['safetyRatings'];
            foreach ($candidate['safetyRatings'] as $rating) {
                if (is_array($rating) && ($rating['blocked'] ?? false)) {
                    $normalized['blocked'] = true;
                    $normalized['blocked_reason'] = $this->getHarmCategoryLabel(is_string($rating['category'] ?? null) ? (string) $rating['category'] : 'UNKNOWN');
                    break;
                }
            }
        }

        $parts = (is_array($candidate['content'] ?? null) && is_array($candidate['content']['parts'] ?? null)) ? $candidate['content']['parts'] : [];
        $textParts = [];
        $thinkingParts = [];

        if (is_array($parts) && !empty($parts)) {
            foreach ($parts as $part) {
                /** @var array<string, mixed> $part */
                if (!is_array($part)) {
                    continue;
                }
                $isThinking = isset($part['thought']) && true === $part['thought'];

                if ($isThinking) {
                    $thinkingContent = $part['thinkingContent'] ?? null;
                    if (isset($thinkingContent)) {
                        $thinkingParts[] = (string) $thinkingContent;
                    } else {
                        $textPart = $part['text'] ?? null;
                        if (isset($textPart)) {
                            $thinkingParts[] = (string) $textPart;
                        }
                    }
                } else {
                    $textPart = $part['text'] ?? null;
                    if (isset($textPart)) {
                        $textParts[] = (string) $textPart;
                    }
                }
                if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                    $fcName = is_string($part['functionCall']['name'] ?? null) ? (string) $part['functionCall']['name'] : 'unknown';
                    $fcCount = is_array($normalized['function_calls']) ? count($normalized['function_calls']) : 0;
                    /** @var array<string, mixed> $fc */
                    $fc = [
                        'id' => 'call_'.substr(md5($fcName.$fcCount), 0, 12),
                        'name' => $fcName,
                        'args' => is_array($part['functionCall']['args'] ?? null) ? $part['functionCall']['args'] : [],
                    ];
                    $normalized['function_calls'][] = $fc;
                }
            }
        }

        if (!empty($textParts)) {
            $normalized['text'] = implode('', $textParts);
        }

        if (!empty($thinkingParts)) {
            $normalized['thinking'] = implode('', $thinkingParts);
        }

        return $normalized;
    }

    /**
     * Retourne la structure de chunk vide au format Synapse.
     * Utilisée comme base pour la normalisation des réponses API.
     *
     * @return array<string, mixed> Structure : {text, thinking, function_calls[], usage[], safety_ratings[], blocked, blocked_reason}
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
     * Convertit une catégorie Gemini HARM_CATEGORY_* en string lisible.
     *
     * @param string $category Catégorie Gemini (ex: 'HARM_CATEGORY_HATE_SPEECH')
     *
     * @return string Description en français
     */
    private function getHarmCategoryLabel(string $category): string
    {
        $labels = [
            'HARM_CATEGORY_HARASSMENT' => 'harcèlement',
            'HARM_CATEGORY_HATE_SPEECH' => 'discours haineux',
            'HARM_CATEGORY_SEXUALLY_EXPLICIT' => 'contenu explicite',
            'HARM_CATEGORY_DANGEROUS_CONTENT' => 'contenu dangereux',
        ];

        return $labels[$category] ?? $category;
    }

    /**
     * Applique la configuration dynamique depuis le ConfigProvider (DB).
     *
     * Lecture des credentials provider depuis provider_credentials :
     *   - project_id, region → vertexProjectId, vertexRegion
     *   - service_account_json → injecté dans GoogleAuthService
     */
    private function applyDynamicConfig(): void
    {
        $config = $this->configProvider->getConfig();

        if (!empty($config['model']) && is_string($config['model'])) {
            $this->model = $config['model'];
        }

        // Provider credentials (SynapseProvider en DB)
        $creds = is_array($config['provider_credentials'] ?? null) ? $config['provider_credentials'] : [];

        if (!empty($creds)) {
            if (!empty($creds['project_id']) && is_string($creds['project_id'])) {
                $this->vertexProjectId = $creds['project_id'];
            }
            if (!empty($creds['region']) && is_string($creds['region'])) {
                $this->vertexRegion = $creds['region'];
            }
            // Défaut YAML du modèle (ex: vertex_region: global pour les previews)
            $yamlRegion = $this->capabilityRegistry->getCapabilities($this->model)->vertexRegion;
            if (null !== $yamlRegion) {
                $this->vertexRegion = $yamlRegion;
            }
            // Override explicite au niveau du preset (priorité maximale)
            if (!empty($config['vertex_region']) && is_string($config['vertex_region'])) {
                $this->vertexRegion = $config['vertex_region'];
            }
            if (!empty($creds['service_account_json']) && is_string($creds['service_account_json'])) {
                $this->geminiAuthService->setCredentialsJson($creds['service_account_json']);
            }
        }

        // Thinking
        if (isset($config['thinking']) && is_array($config['thinking'])) {
            $this->thinkingEnabled = (bool) ($config['thinking']['enabled'] ?? $this->thinkingEnabled);
            $this->thinkingBudget = (int) ($config['thinking']['budget'] ?? $this->thinkingBudget);
        }

        // Safety Settings
        if (isset($config['safety_settings']) && is_array($config['safety_settings'])) {
            $this->safetySettingsEnabled = (bool) ($config['safety_settings']['enabled'] ?? $this->safetySettingsEnabled);
            $this->safetyDefaultThreshold = (string) ($config['safety_settings']['default_threshold'] ?? $this->safetyDefaultThreshold);
            if (isset($config['safety_settings']['thresholds']) && is_array($config['safety_settings']['thresholds'])) {
                /** @var array<string, string> $thresholds */
                $thresholds = $config['safety_settings']['thresholds'];
                $this->safetyThresholds = $thresholds;
            }
        }

        // Generation Config
        if (isset($config['generation_config']) && is_array($config['generation_config'])) {
            $gen = $config['generation_config'];
            if (isset($gen['temperature']) && is_numeric($gen['temperature'])) {
                $this->generationTemperature = (float) $gen['temperature'];
            }
            if (isset($gen['top_p']) && is_numeric($gen['top_p'])) {
                $this->generationTopP = (float) $gen['top_p'];
            }
            if (isset($gen['top_k']) && is_numeric($gen['top_k'])) {
                $this->generationTopK = (int) $gen['top_k'];
            }
            $this->generationMaxOutputTokens = !empty($gen['max_output_tokens']) ? (int) $gen['max_output_tokens'] : null;
        }
    }

    private function findObjectEnd(string $buffer): ?int
    {
        $len = strlen($buffer);
        $depth = 0;
        $inString = false;
        $escaped = false;

        for ($i = 0; $i < $len; ++$i) {
            $char = $buffer[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ('\\' === $char) {
                $escaped = true;
                continue;
            }

            if ('"' === $char) {
                $inString = !$inString;
                continue;
            }

            if (!$inString) {
                if ('{' === $char) {
                    ++$depth;
                } elseif ('}' === $char) {
                    --$depth;
                    if (0 === $depth) {
                        return $i;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Construit l'URL d'appel API Vertex AI pour le modèle spécifié.
     * Substitue region, project_id et model_id dans le template URL.
     * Le model_id est obtenu depuis ModelCapabilityRegistry (peut différer du model passé).
     *
     * @param string $template Template URL Vertex : https://REGION-aiplatform.googleapis.com/v1/projects/PROJECT/locations/REGION/publishers/google/models/MODEL:endpoint
     * @param string $model Identifiant du modèle (ex: 'gemini-2.5-flash')
     *
     * @return string URL complète et fonctionnelle pour l'API Vertex
     */
    private function buildVertexUrl(string $template, string $model): string
    {
        $caps = $this->capabilityRegistry->getCapabilities($model);
        $finalModelId = $caps->model ?? $model;
        $region = $this->vertexRegion;

        // Pour la région "global", Vertex AI utilise un endpoint sans préfixe région
        if ('global' === $region) {
            $template = str_replace('%s-aiplatform.googleapis.com', 'aiplatform.googleapis.com', $template);

            return sprintf(
                $template,
                $this->vertexProjectId,
                $region,
                $finalModelId
            );
        }

        return sprintf(
            $template,
            $region,
            $this->vertexProjectId,
            $region,
            $finalModelId
        );
    }

    /**
     * Convert OpenAI canonical format messages to Gemini format.
     * This is needed because the system uses OpenAI format internally,
     * but Vertex AI expects Gemini format.
     *
     * @param array<int, array<string, mixed>> $openAiMessages Messages in format:
     *                                                         ['role' => 'user'|'assistant'|'tool', 'content' => ..., 'tool_calls' => [...], 'tool_call_id' => ...]
     *
     * @return array<int, array<string, mixed>> Messages in Gemini format:
     *                                          ['role' => 'user'|'model'|'function', 'parts' => [...]]
     */
    private function toGeminiMessages(array $openAiMessages): array
    {
        $geminiMessages = [];

        foreach ($openAiMessages as $msg) {
            $role = $msg['role'] ?? '';

            if ('user' === $role) {
                $content = $msg['content'] ?? '';

                if (is_array($content)) {
                    // Contenu multipart : texte + images (vision)
                    $parts = [];
                    foreach ($content as $part) {
                        $partType = $part['type'] ?? '';
                        if ('text' === $partType) {
                            $parts[] = ['text' => $part['text'] ?? ''];
                        } elseif ('image_url' === $partType) {
                            $url = $part['image_url']['url'] ?? '';
                            if (str_starts_with($url, 'data:')) {
                                // data URL base64 → inlineData Gemini
                                [$meta, $b64] = explode(',', $url, 2);
                                $mimeType = str_replace('data:', '', explode(';', $meta)[0]);
                                $parts[] = ['inlineData' => ['mimeType' => $mimeType, 'data' => $b64]];
                            } else {
                                // URL externe → fileData
                                $parts[] = ['fileData' => ['fileUri' => $url]];
                            }
                        }
                        // Extensible : audio, video, document en Phase 3
                    }
                    $geminiMessages[] = ['role' => 'user', 'parts' => $parts];
                } else {
                    $geminiMessages[] = [
                        'role' => 'user',
                        'parts' => [['text' => is_scalar($content) ? (string) $content : json_encode($content)]],
                    ];
                }
            } elseif ('assistant' === $role) {
                $parts = [];

                // Add text content if present
                if (!empty($msg['content'])) {
                    $parts[] = ['text' => $msg['content']];
                }

                // Add function calls if present
                /** @var array<int, array<string, mixed>> $toolCalls */
                $toolCalls = is_array($msg['tool_calls'] ?? null) ? $msg['tool_calls'] : [];
                foreach ($toolCalls as $tc) {
                    $tcFunction = is_array($tc['function'] ?? null) ? $tc['function'] : [];
                    $argsStr = is_string($tcFunction['arguments'] ?? null) ? (string) $tcFunction['arguments'] : '{}';
                    $args = json_decode((string) $argsStr, true) ?? [];
                    if (!empty($tcFunction['name'])) {
                        $parts[] = [
                            'functionCall' => [
                                'name' => (string) $tcFunction['name'],
                                'args' => !empty($args) && is_array($args) ? $args : (object) [],
                            ],
                        ];
                    }
                }

                if (!empty($parts)) {
                    $geminiMessages[] = [
                        'role' => 'model',
                        'parts' => $parts,
                    ];
                }
            } elseif ('tool' === $role) {
                // Find the function name from the corresponding tool_call_id in previous assistant messages
                $toolName = $this->resolveFunctionName($geminiMessages, (string) ($msg['tool_call_id'] ?? ''));

                if (!empty($toolName)) {
                    $response = json_decode(is_scalar($msg['content'] ?? null) ? (string) ($msg['content'] ?? '{}') : '{}', true);
                    if (!is_array($response)) {
                        $response = ['content' => $msg['content']];
                    }

                    $geminiMessages[] = [
                        'role' => 'function',
                        'parts' => [
                            [
                                'functionResponse' => [
                                    'name' => $toolName,
                                    'response' => !empty($response) ? $response : (object) [],
                                ],
                            ],
                        ],
                    ];
                }
            }
        }

        return $geminiMessages;
    }

    /**
     * Resolve function name from tool_call_id by searching previous assistant messages.
     *
     * @param array<int, array<string, mixed>> $geminiMessages
     */
    private function resolveFunctionName(array $geminiMessages, string $toolCallId): string
    {
        // Search backwards through messages to find the corresponding tool call
        // This is a simplified approach — we'll just use the tool_call_id as a hint
        // In practice, for Gemini which doesn't use IDs, we can extract the last function call name
        foreach (array_reverse($geminiMessages) as $msg) {
            if (($msg['role'] ?? '') === 'model') {
                $parts = is_array($msg['parts'] ?? null) ? $msg['parts'] : [];
                foreach ($parts as $part) {
                    /** @var array<string, mixed> $part */
                    if (is_array($part) && isset($part['functionCall']) && is_array($part['functionCall'])) {
                        return is_scalar($part['functionCall']['name'] ?? null) ? (string) $part['functionCall']['name'] : '';
                    }
                }
            }
        }

        return '';
    }

    /**
     * Construit le payload de requête Vertex AI.
     *
     * @param array<int, array<string, mixed>> $contents
     * @param array<int, array<string, mixed>> $tools
     * @param array<string, mixed>|null $thinkingConfigOverride
     *
     * @return array<string, mixed>
     */
    private function buildPayload(
        array $contents,
        array $tools,
        string $effectiveModel,
        /* @var array<string, mixed>|null $thinkingConfigOverrideVar */
        ?array $thinkingConfigOverride,
    ): array {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        // Extract system instruction from contents (first message with role: 'system')
        $systemInstruction = '';
        $contentsWithoutSystem = $contents;

        if (!empty($contents) && ($contents[0]['role'] ?? '') === 'system') {
            $systemInstruction = $contents[0]['content'] ?? '';
            $contentsWithoutSystem = array_slice($contents, 1);
        }

        // Convert OpenAI format to Gemini format for the API
        $geminiContents = $this->toGeminiMessages($contentsWithoutSystem);

        $payload = [
            'systemInstruction' => [
                'parts' => [
                    ['text' => $systemInstruction],
                ],
            ],
            'contents' => $geminiContents,
        ];

        $generationConfig = $this->buildGenerationConfig($effectiveModel, $thinkingConfigOverride);
        if (!empty($generationConfig)) {
            $payload['generationConfig'] = $generationConfig;
        }

        if ($caps->supportsSafetySettings) {
            $safetySettings = $this->buildSafetySettings();
            if (!empty($safetySettings)) {
                $payload['safetySettings'] = $safetySettings;
            }
        } else {
            $payload['safetySettings'] = $this->buildSafetySettingsBlockNone();
        }

        if (!empty($tools) && $caps->supportsFunctionCalling) {
            $firstTool = reset($tools);
            $isFlatFunctionList = is_array($firstTool)
                && isset($firstTool['name'])
                && !isset($firstTool['function_declarations']);

            if ($isFlatFunctionList) {
                $payload['tools'] = [
                    ['function_declarations' => $tools],
                ];
            } else {
                $payload['tools'] = $tools;
            }
        }

        return $payload;
    }

    /**
     * Construit les headers HTTP d'authentification pour Vertex AI.
     * Obtient un token Bearer OAuth2 via GoogleAuthService.
     *
     * @return array<string, string> Headers : {Authorization: 'Bearer ...', Content-Type: 'application/json'}
     */
    private function buildVertexHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->geminiAuthService->getAccessToken(),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Construit la configuration de génération pour l'appel API Vertex.
     * Applique les paramètres de température, top_p, top_k, tokens max, etc.
     * Intègre optionnellement une config thinking (peut être surcharge par paramètre).
     * Filtre les paramètres selon les capacités du modèle (ModelCapabilityRegistry).
     *
     * @param string $effectiveModel Identifiant du modèle
     * @param array<string, mixed>|null $thinkingConfigOverride Config thinking optionnelle (surcharge buildThinkingConfig)
     *
     * @return array<string, mixed> Config Gemini : {temperature, topP, topK?, maxOutputTokens?, stopSequences?, thinkingConfig?}
     */
    private function buildGenerationConfig(string $effectiveModel, ?array $thinkingConfigOverride = null): array
    {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $config = [
            'temperature' => $this->generationTemperature,
            'topP' => $this->generationTopP,
        ];

        if ($caps->supportsTopK) {
            $config['topK'] = $this->generationTopK;
        }

        if (null !== $this->generationMaxOutputTokens) {
            $config['maxOutputTokens'] = $this->generationMaxOutputTokens;
        }

        if (!empty($this->generationStopSequences)) {
            $config['stopSequences'] = $this->generationStopSequences;
        }

        $thinkingConfig = $thinkingConfigOverride ?? $this->buildThinkingConfig($effectiveModel);
        if ($thinkingConfig) {
            $config['thinkingConfig'] = $thinkingConfig;
        }

        return $config;
    }

    /**
     * Construit la configuration thinking (ou retourne null si désactivé).
     * Le thinking n'est envoyé que si : (1) activé en config ET (2) supporté par le modèle.
     *
     * @param string $effectiveModel Identifiant du modèle
     *
     * @return array<string, mixed>|null Config thinking : {thinkingBudget: int, includeThoughts: true} ou null
     */
    private function buildThinkingConfig(string $effectiveModel): ?array
    {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        if (!$this->thinkingEnabled || !$caps->supportsThinking) {
            return null;
        }

        return [
            'thinkingBudget' => $this->thinkingBudget,
            'includeThoughts' => true,
        ];
    }

    /**
     * Construit les seuils de sécurité configurés pour chaque catégorie de contenu.
     * Si safety_settings est désactivé, délègue à buildSafetySettingsBlockNone().
     * Sinon, applique les seuils par catégorie (ou défaut si non configurée).
     *
     * @return array<int, array{category: string, threshold: string}> Seuils : [{category: HARM_CATEGORY_*, threshold: BLOCK_*}, ...]
     */
    private function buildSafetySettings(): array
    {
        $categoryMapping = [
            'hate_speech' => 'HARM_CATEGORY_HATE_SPEECH',
            'dangerous_content' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'harassment' => 'HARM_CATEGORY_HARASSMENT',
            'sexually_explicit' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        ];

        if (!$this->safetySettingsEnabled) {
            return $this->buildSafetySettingsBlockNone();
        }

        $settings = [];
        foreach ($categoryMapping as $configKey => $apiCategory) {
            $threshold = $this->safetyThresholds[$configKey] ?? $this->safetyDefaultThreshold;
            $settings[] = [
                'category' => $apiCategory,
                'threshold' => $threshold,
            ];
        }

        return $settings;
    }

    /**
     * Construit les seuils de sécurité avec BLOCK_NONE sur toutes les catégories.
     * Utilisé quand safety_settings est désactivé (aucun filtrage).
     *
     * @return array<int, array{category: string, threshold: 'BLOCK_NONE'}> Seuils : [{category: HARM_CATEGORY_*, threshold: 'BLOCK_NONE'}, ...]
     */
    private function buildSafetySettingsBlockNone(): array
    {
        $categories = [
            'HARM_CATEGORY_HATE_SPEECH',
            'HARM_CATEGORY_DANGEROUS_CONTENT',
            'HARM_CATEGORY_HARASSMENT',
            'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        ];

        return array_map(fn ($cat) => ['category' => $cat, 'threshold' => 'BLOCK_NONE'], $categories);
    }

    public function getCredentialFields(): array
    {
        return [
            'project_id' => [
                'label' => 'Project ID Google Cloud',
                'type' => 'text',
                'help' => 'Identifiant de votre projet Google Cloud Platform.',
                'placeholder' => 'my-gcp-project',
                'required' => true,
            ],
            'region' => [
                'label' => 'Région Vertex AI',
                'type' => 'select',
                'required' => true,
                'options' => [
                    'global' => 'Global (modèles preview & nouveaux)',
                    'europe-west9' => 'Europe West 9 (Paris)',
                    'europe-west1' => 'Europe West 1 (Belgique)',
                    'europe-west4' => 'Europe West 4 (Pays-Bas)',
                    'europe-west3' => 'Europe West 3 (Francfort)',
                    'europe-west2' => 'Europe West 2 (Londres)',
                    'us-central1' => 'US Central 1 (Iowa)',
                    'us-east1' => 'US East 1 (Caroline du Sud)',
                    'asia-east1' => 'Asia East 1 (Taiwan)',
                    'asia-northeast1' => 'Asia Northeast 1 (Tokyo)',
                ],
            ],
            'service_account_json' => [
                'label' => 'Service Account JSON',
                'type' => 'textarea',
                'help' => 'Collez le contenu complet du fichier JSON du Service Account Google Cloud. Ce fichier est généré depuis IAM → Comptes de service → Clés.',
                'placeholder' => '{"type": "service_account", "project_id": "...", "private_key": "...", ...}',
                'required' => true,
                'is_code' => true,
            ],
        ];
    }

    public function validateCredentials(array $credentials): void
    {
        $projectId = $credentials['project_id'] ?? '';
        if (empty($projectId)) {
            throw new \Exception('Project ID manquant');
        }

        $jsonStr = $credentials['service_account_json'] ?? '';
        if (empty($jsonStr)) {
            throw new \Exception('Service Account JSON manquant');
        }

        /** @var string $jsonStrStr */
        $jsonStrStr = (string) $jsonStr;
        $json = json_decode($jsonStrStr, true);
        if (!is_array($json) || empty($json['project_id'])) {
            throw new \Exception('Service Account JSON invalide');
        }

        if ($json['project_id'] !== $projectId) {
            throw new \Exception('Project ID ne correspond pas au JSON');
        }
    }

    public function getDefaultLabel(): string
    {
        return 'Google Vertex AI';
    }

    /**
     * Transforme toute exception en RuntimeException avec contexte API.
     * Pour les HttpExceptionInterface, enrichit le message avec la réponse d'erreur Google.
     * Conserve la exception d'origine comme cause (previous).
     *
     * @param \Throwable $e Exception originelle (HttpExceptionInterface, network, etc.)
     *
     * @throws \RuntimeException Toujours levée avec message normalisé
     *
     * @return void N'existe jamais — lève toujours \RuntimeException
     */
    private function handleException(\Throwable $e): void
    {
        $message = $e->getMessage();
        $statusCode = null;

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getResponse()->getStatusCode();
            try {
                $errorBody = $e->getResponse()->getContent(false);
                $message .= ' || Google Error: '.$errorBody;
            } catch (\Throwable) {
            }
        }

        $fullMsg = 'Gemini API Error: '.$message;

        throw match ($statusCode) {
            401, 403 => new LlmAuthenticationException($fullMsg, 0, $e),
            429 => new LlmRateLimitException($fullMsg, 0, $e),
            500, 503 => new LlmServiceUnavailableException($fullMsg, 0, $e),
            default => (str_contains(strtolower($message), 'quota') ? new LlmQuotaException($fullMsg, 0, $e) : new LlmException($fullMsg, 0, $e)),
        };
    }
}
