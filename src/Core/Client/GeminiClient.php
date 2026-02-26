<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Client;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EmbeddingClientInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmAuthenticationException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmRateLimitException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmServiceUnavailableException;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
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
    private const VERTEX_URL        = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:generateContent';
    private const VERTEX_STREAM_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:streamGenerateContent';
    private const VERTEX_EMBEDDING_URL = 'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models/%s:predict';

    // ── Config runtime (chargée depuis DB via applyDynamicConfig) ────────────
    private string $model                   = 'gemini-2.5-flash';
    private string $vertexProjectId         = '';
    private string $vertexRegion            = 'europe-west1';
    private bool   $thinkingEnabled         = true;
    private int    $thinkingBudget          = 1024;
    private bool   $safetySettingsEnabled   = false;
    private string $safetyDefaultThreshold  = 'BLOCK_MEDIUM_AND_ABOVE';
    private array  $safetyThresholds        = [];
    private float  $generationTemperature   = 1.0;
    private float  $generationTopP          = 0.95;
    private int    $generationTopK          = 40;
    private ?int   $generationMaxOutputTokens = null;
    private array  $generationStopSequences = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        private GeminiAuthService $geminiAuthService,
        private ConfigProviderInterface $configProvider,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {}

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
        $payload = $this->buildPayload($contents, $tools, $effectiveModel, $options['thinking'] ?? null);

        // Capture les paramètres réellement envoyés (après filtrage par ModelCapabilityRegistry)
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);
        $debugOut['actual_request_params'] = [
            'model'              => $effectiveModel,
            'provider'           => 'gemini',
            'temperature'        => $this->generationTemperature,
            'top_p'              => $this->generationTopP,
            'top_k'              => $caps->topK ? $this->generationTopK : null,
            'max_output_tokens'  => $this->generationMaxOutputTokens,
            'thinking_enabled'   => $this->thinkingEnabled && $caps->thinking,
            'thinking_budget'    => ($this->thinkingEnabled && $caps->thinking) ? $this->thinkingBudget : null,
            'safety_enabled'     => $this->safetySettingsEnabled,
            'tools_sent'         => !empty($tools) && $caps->functionCalling,
            'system_prompt_sent' => !empty($contents) && ($contents[0]['role'] ?? '') === 'system',
        ];
        $debugOut['raw_request_body'] = TextUtil::sanitizeArrayUtf8($payload);

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json'    => TextUtil::sanitizeArrayUtf8($payload),
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
                'outputDimensionality' => (int) $options['output_dimensionality'],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json'    => TextUtil::sanitizeArrayUtf8($payload),
                'headers' => $this->buildVertexHeaders(),
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            $embeddings = [];
            if (isset($data['predictions']) && is_array($data['predictions'])) {
                foreach ($data['predictions'] as $prediction) {
                    if (isset($prediction['embeddings']['values'])) {
                        $embeddings[] = $prediction['embeddings']['values'];
                    }
                }
            }

            // Vertex AI embeddings API often returns billableCharacterCount instead of tokens
            // Approximation: 1 token ~= 4 chars pour l'anglais/français
            $billableChars = $data['metadata']['billableCharacterCount'] ?? 0;
            $totalTokens = (int) ceil($billableChars / 4.0);

            $usage = [
                'prompt_tokens' => $totalTokens,
                'total_tokens'  => $totalTokens,
            ];

            return [
                'embeddings' => $embeddings,
                'usage'      => $usage,
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
     * @return \Generator<array>
     * @throws \RuntimeException En cas d'erreur API Vertex AI
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
            'model'              => $effectiveModel,
            'provider'           => 'gemini',
            'temperature'        => $this->generationTemperature,
            'top_p'              => $this->generationTopP,
            'top_k'              => $caps->topK ? $this->generationTopK : null,
            'max_output_tokens'  => $this->generationMaxOutputTokens,
            'thinking_enabled'   => $this->thinkingEnabled && $caps->thinking,
            'thinking_budget'    => ($this->thinkingEnabled && $caps->thinking) ? $this->thinkingBudget : null,
            'safety_enabled'     => $this->safetySettingsEnabled,
            'tools_sent'         => !empty($tools) && $caps->functionCalling,
            'system_prompt_sent' => !empty($contents) && ($contents[0]['role'] ?? '') === 'system',
        ];
        $debugOut['raw_request_body'] = TextUtil::sanitizeArrayUtf8($payload);

        $rawApiChunks = [];

        try {
            $response = $this->httpClient->request('POST', $url, [
                'json'    => TextUtil::sanitizeArrayUtf8($payload),
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

                    if ($objEnd === null) {
                        break;
                    }

                    $jsonStr  = substr($buffer, 0, $objEnd + 1);
                    $jsonData = json_decode($jsonStr, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        // Capture le chunk brut AVANT normalisation (pour debug)
                        $rawApiChunks[] = $jsonData;
                        yield $this->normalizeChunk($jsonData);
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
     */
    private function normalizeChunk(array $rawChunk): array
    {
        $normalized = $this->emptyChunk();

        if (isset($rawChunk['usageMetadata'])) {
            $u = $rawChunk['usageMetadata'];
            $normalized['usage'] = [
                'prompt_tokens'     => $u['promptTokenCount'] ?? 0,
                'completion_tokens' => $u['candidatesTokenCount'] ?? 0,
                'thinking_tokens'   => $u['thoughtsTokenCount'] ?? 0,
                'total_tokens'      => $u['totalTokenCount'] ?? 0,
            ];
        }

        $candidate = $rawChunk['candidates'][0] ?? [];

        if (isset($candidate['safetyRatings'])) {
            $normalized['safety_ratings'] = $candidate['safetyRatings'];
            foreach ($candidate['safetyRatings'] as $rating) {
                if ($rating['blocked'] ?? false) {
                    $normalized['blocked']        = true;
                    $normalized['blocked_reason'] = $this->getHarmCategoryLabel($rating['category'] ?? 'UNKNOWN');
                    break;
                }
            }
        }

        $parts         = $candidate['content']['parts'] ?? [];
        $textParts     = [];
        $thinkingParts = [];

        foreach ($parts as $part) {
            $isThinking = isset($part['thought']) && true === $part['thought'];

            if ($isThinking) {
                if (isset($part['thinkingContent'])) {
                    $thinkingParts[] = $part['thinkingContent'];
                } elseif (isset($part['text'])) {
                    $thinkingParts[] = $part['text'];
                }
            } elseif (isset($part['text'])) {
                $textParts[] = $part['text'];
            } elseif (isset($part['functionCall'])) {
                $normalized['function_calls'][] = [
                    'id'   => 'call_' . substr(md5($part['functionCall']['name'] . count($normalized['function_calls'])), 0, 12),
                    'name' => $part['functionCall']['name'],
                    'args' => $part['functionCall']['args'] ?? [],
                ];
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
            'text'           => null,
            'thinking'       => null,
            'function_calls' => [],
            'usage'          => [],
            'safety_ratings' => [],
            'blocked'        => false,
            'blocked_reason' => null,
        ];
    }

    /**
     * Convertit une catégorie Gemini HARM_CATEGORY_* en string lisible.
     *
     * @param string $category Catégorie Gemini (ex: 'HARM_CATEGORY_HATE_SPEECH')
     * @return string Description en français
     */
    private function getHarmCategoryLabel(string $category): string
    {
        $labels = [
            'HARM_CATEGORY_HARASSMENT'        => 'harcèlement',
            'HARM_CATEGORY_HATE_SPEECH'       => 'discours haineux',
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

        if (!empty($config['model'])) {
            $this->model = $config['model'];
        }

        // Provider credentials (SynapseProvider en DB)
        if (!empty($config['provider_credentials'])) {
            $creds = $config['provider_credentials'];

            if (!empty($creds['project_id'])) {
                $this->vertexProjectId = $creds['project_id'];
            }
            if (!empty($creds['region'])) {
                $this->vertexRegion = $creds['region'];
            }
            if (!empty($creds['service_account_json'])) {
                $this->geminiAuthService->setCredentialsJson($creds['service_account_json']);
            }
        }

        // Thinking
        if (isset($config['thinking'])) {
            $this->thinkingEnabled = $config['thinking']['enabled'] ?? $this->thinkingEnabled;
            $this->thinkingBudget  = $config['thinking']['budget'] ?? $this->thinkingBudget;
        }

        // Safety Settings
        if (isset($config['safety_settings'])) {
            $this->safetySettingsEnabled  = $config['safety_settings']['enabled'] ?? $this->safetySettingsEnabled;
            $this->safetyDefaultThreshold = $config['safety_settings']['default_threshold'] ?? $this->safetyDefaultThreshold;
            $this->safetyThresholds       = $config['safety_settings']['thresholds'] ?? $this->safetyThresholds;
        }

        // Generation Config
        if (isset($config['generation_config'])) {
            $gen = $config['generation_config'];
            $this->generationTemperature     = (float) ($gen['temperature'] ?? $this->generationTemperature);
            $this->generationTopP            = (float) ($gen['top_p'] ?? $this->generationTopP);
            $this->generationTopK            = (int) ($gen['top_k'] ?? $this->generationTopK);
        }
    }

    private function findObjectEnd(string $buffer): ?int
    {
        $len      = strlen($buffer);
        $depth    = 0;
        $inString = false;
        $escaped  = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $buffer[$i];

            if ($escaped) {
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                continue;
            }

            if ($char === '"') {
                $inString = !$inString;
                continue;
            }

            if (!$inString) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
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
     * @return string URL complète et fonctionnelle pour l'API Vertex
     */
    private function buildVertexUrl(string $template, string $model): string
    {
        $caps = $this->capabilityRegistry->getCapabilities($model);
        $finalModelId = $caps->modelId ?? $model;

        return sprintf(
            $template,
            $this->vertexRegion,
            $this->vertexProjectId,
            $this->vertexRegion,
            $finalModelId
        );
    }

    /**
     * Convert OpenAI canonical format messages to Gemini format.
     * This is needed because the system uses OpenAI format internally,
     * but Vertex AI expects Gemini format.
     *
     * @param array $openAiMessages Messages in format:
     *   ['role' => 'user'|'assistant'|'tool', 'content' => ..., 'tool_calls' => [...], 'tool_call_id' => ...]
     * @return array Messages in Gemini format:
     *   ['role' => 'user'|'model'|'function', 'parts' => [...]]
     */
    private function toGeminiMessages(array $openAiMessages): array
    {
        $geminiMessages = [];

        foreach ($openAiMessages as $msg) {
            $role = $msg['role'] ?? '';

            if ($role === 'user') {
                $content = $msg['content'] ?? '';
                $geminiMessages[] = [
                    'role'  => 'user',
                    'parts' => [['text' => (string)$content]],
                ];
            } elseif ($role === 'assistant') {
                $parts = [];

                // Add text content if present
                if (!empty($msg['content'])) {
                    $parts[] = ['text' => $msg['content']];
                }

                // Add function calls if present
                foreach ($msg['tool_calls'] ?? [] as $tc) {
                    $args = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
                    $parts[] = [
                        'functionCall' => [
                            'name' => $tc['function']['name'],
                            'args' => !empty($args) ? $args : (object)[],
                        ],
                    ];
                }

                if (!empty($parts)) {
                    $geminiMessages[] = [
                        'role'  => 'model',
                        'parts' => $parts,
                    ];
                }
            } elseif ($role === 'tool') {
                // Find the function name from the corresponding tool_call_id in previous assistant messages
                $toolName = $this->resolveFunctionName($geminiMessages, $msg['tool_call_id'] ?? '');

                if (!empty($toolName)) {
                    $response = json_decode($msg['content'] ?? '{}', true);
                    if (!is_array($response)) {
                        $response = ['content' => $msg['content']];
                    }

                    $geminiMessages[] = [
                        'role'  => 'function',
                        'parts' => [
                            [
                                'functionResponse' => [
                                    'name'     => $toolName,
                                    'response' => !empty($response) ? $response : (object)[],
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
     */
    private function resolveFunctionName(array $geminiMessages, string $toolCallId): string
    {
        // Search backwards through messages to find the corresponding tool call
        // This is a simplified approach — we'll just use the tool_call_id as a hint
        // In practice, for Gemini which doesn't use IDs, we can extract the last function call name
        foreach (array_reverse($geminiMessages) as $msg) {
            if (($msg['role'] ?? '') === 'model') {
                foreach ($msg['parts'] ?? [] as $part) {
                    if (isset($part['functionCall'])) {
                        return $part['functionCall']['name'];
                    }
                }
            }
        }
        return '';
    }

    private function buildPayload(
        array $contents,
        array $tools,
        string $effectiveModel,
        ?array $thinkingConfigOverride
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

        if ($caps->safetySettings) {
            $safetySettings = $this->buildSafetySettings();
            if (!empty($safetySettings)) {
                $payload['safetySettings'] = $safetySettings;
            }
        } else {
            $payload['safetySettings'] = $this->buildSafetySettingsBlockNone();
        }

        if (!empty($tools) && $caps->functionCalling) {
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
            'Authorization' => 'Bearer ' . $this->geminiAuthService->getAccessToken(),
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * Construit la configuration de génération pour l'appel API Vertex.
     * Applique les paramètres de température, top_p, top_k, tokens max, etc.
     * Intègre optionnellement une config thinking (peut être surcharge par paramètre).
     * Filtre les paramètres selon les capacités du modèle (ModelCapabilityRegistry).
     *
     * @param string $effectiveModel Identifiant du modèle
     * @param array|null $thinkingConfigOverride Config thinking optionnelle (surcharge buildThinkingConfig)
     * @return array<string, mixed> Config Gemini : {temperature, topP, topK?, maxOutputTokens?, stopSequences?, thinkingConfig?}
     */
    private function buildGenerationConfig(string $effectiveModel, ?array $thinkingConfigOverride = null): array
    {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        $config = [
            'temperature' => $this->generationTemperature,
            'topP'        => $this->generationTopP,
        ];

        if ($caps->topK) {
            $config['topK'] = $this->generationTopK;
        }

        if ($this->generationMaxOutputTokens !== null) {
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
     * @return array<string, mixed>|null Config thinking : {thinkingBudget: int, includeThoughts: true} ou null
     */
    private function buildThinkingConfig(string $effectiveModel): ?array
    {
        $caps = $this->capabilityRegistry->getCapabilities($effectiveModel);

        if (!$this->thinkingEnabled || !$caps->thinking) {
            return null;
        }

        return [
            'thinkingBudget'  => $this->thinkingBudget,
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
            'hate_speech'       => 'HARM_CATEGORY_HATE_SPEECH',
            'dangerous_content' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'harassment'        => 'HARM_CATEGORY_HARASSMENT',
            'sexually_explicit' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
        ];

        if (!$this->safetySettingsEnabled) {
            return $this->buildSafetySettingsBlockNone();
        }

        $settings = [];
        foreach ($categoryMapping as $configKey => $apiCategory) {
            $threshold  = $this->safetyThresholds[$configKey] ?? $this->safetyDefaultThreshold;
            $settings[] = [
                'category'  => $apiCategory,
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

        return array_map(fn($cat) => ['category' => $cat, 'threshold' => 'BLOCK_NONE'], $categories);
    }

    public function getCredentialFields(): array
    {
        return [
            'project_id' => [
                'label'       => 'Project ID Google Cloud',
                'type'        => 'text',
                'help'        => 'Identifiant de votre projet Google Cloud Platform.',
                'placeholder' => 'my-gcp-project',
                'required'    => true,
            ],
            'region' => [
                'label'    => 'Région Vertex AI',
                'type'     => 'select',
                'required' => true,
                'options'  => [
                    'europe-west9'    => 'Europe West 9 (Paris)',
                    'europe-west1'    => 'Europe West 1 (Belgique)',
                    'europe-west4'    => 'Europe West 4 (Pays-Bas)',
                    'europe-west3'    => 'Europe West 3 (Francfort)',
                    'europe-west2'    => 'Europe West 2 (Londres)',
                    'us-central1'     => 'US Central 1 (Iowa)',
                    'us-east1'        => 'US East 1 (Caroline du Sud)',
                    'asia-east1'      => 'Asia East 1 (Taiwan)',
                    'asia-northeast1' => 'Asia Northeast 1 (Tokyo)',
                ],
            ],
            'service_account_json' => [
                'label'       => 'Service Account JSON',
                'type'        => 'textarea',
                'help'        => 'Collez le contenu complet du fichier JSON du Service Account Google Cloud. Ce fichier est généré depuis IAM → Comptes de service → Clés.',
                'placeholder' => '{"type": "service_account", "project_id": "...", "private_key": "...", ...}',
                'required'    => true,
                'is_code'     => true,
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

        $json = json_decode($jsonStr, true);
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
     * @return void N'existe jamais — lève toujours \RuntimeException
     * @throws \RuntimeException Toujours levée avec message normalisé
     */
    private function handleException(\Throwable $e): void
    {
        $message = $e->getMessage();
        $statusCode = null;

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getResponse()->getStatusCode();
            try {
                $errorBody = $e->getResponse()->getContent(false);
                $message .= ' || Google Error: ' . $errorBody;
            } catch (\Throwable) {
            }
        }

        $fullMsg = 'Gemini API Error: ' . $message;

        throw match ($statusCode) {
            401, 403 => new LlmAuthenticationException($fullMsg, 0, $e),
            429      => new LlmRateLimitException($fullMsg, 0, $e),
            500, 503 => new LlmServiceUnavailableException($fullMsg, 0, $e),
            default  => (str_contains(strtolower($message), 'quota') ? new LlmQuotaException($fullMsg, 0, $e) : new LlmException($fullMsg, 0, $e)),
        };
    }
}
