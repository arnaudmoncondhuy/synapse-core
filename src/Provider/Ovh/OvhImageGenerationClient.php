<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Provider\Ovh;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ImageGenerationClientInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmAuthenticationException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmRateLimitException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmServiceUnavailableException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\GeneratedImage;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de génération d'image via OVH AI Endpoints (Stable Diffusion XL et autres).
 *
 * Compatible avec l'API OpenAI Images (POST /v1/images/generations).
 * Les credentials sont lus depuis le provider 'ovh' en base de données.
 *
 * Endpoint : {endpoint}/images/generations
 * Auth     : Bearer {api_key}
 * Format   : OpenAI Images API (response_format: b64_json)
 */
#[Autoconfigure(tags: ['synapse.image_generation_client'])]
class OvhImageGenerationClient implements ImageGenerationClientInterface
{
    private const DEFAULT_ENDPOINT = 'https://oai.endpoints.kepler.ai.cloud.ovh.net/v1';
    private const DEFAULT_MODEL = 'stable-diffusion-xl';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ?SynapseProviderRepository $providerRepository = null,
        private readonly ?EncryptionServiceInterface $encryptionService = null,
    ) {
    }

    public function getProviderName(): string
    {
        return 'ovh';
    }

    /**
     * Génère une image via OVH Stable Diffusion XL.
     *
     * Note : l'API OVH ne supporte pas de paramètre de taille (size/width/height) —
     * la résolution est fixe côté serveur. Seuls prompt, model et negative_prompt
     * sont pris en compte (cf. openapi.json de l'endpoint OVH).
     *
     * @param array{
     *     model?: string,
     *     negative_prompt?: string
     * } $options
     *
     * @return list<GeneratedImage>
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        [$apiKey, $endpoint] = $this->loadCredentials();

        $model = $options['model'] ?? self::DEFAULT_MODEL;

        $payload = [
            'model' => $model,
            'prompt' => $prompt,
            'response_format' => 'b64_json',
        ];

        if (!empty($options['negative_prompt'])) {
            $payload['negative_prompt'] = $options['negative_prompt'];
        }

        try {
            $response = $this->httpClient->request('POST', rtrim($endpoint, '/').'/images/generations', [
                'headers' => [
                    'Authorization' => 'Bearer '.$apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = $response->toArray();

            $images = [];
            $items = is_array($data['data'] ?? null) ? $data['data'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $b64 = is_string($item['b64_json'] ?? null) ? (string) $item['b64_json'] : null;
                if (null === $b64 || '' === $b64) {
                    continue;
                }
                $revisedPrompt = is_string($item['revised_prompt'] ?? null) ? (string) $item['revised_prompt'] : null;
                $images[] = new GeneratedImage(data: $b64, mimeType: 'image/png', revisedPrompt: $revisedPrompt);
            }

            return $images;
        } catch (HttpExceptionInterface $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            try {
                $errorBody = $e->getResponse()->getContent(false);
                $errorData = json_decode($errorBody, true);
                $message = is_array($errorData) && isset($errorData['message']) ? (string) $errorData['message'] : $errorBody;
            } catch (\Throwable) {
                $message = $e->getMessage();
            }
            $fullMsg = 'OVH Image API Error: '.$message;
            throw match ($statusCode) {
                401, 403 => new LlmAuthenticationException($fullMsg, 0, $e),
                429 => new LlmRateLimitException($fullMsg, 0, $e),
                500, 503 => new LlmServiceUnavailableException($fullMsg, 0, $e),
                default => new LlmException($fullMsg, 0, $e),
            };
        } catch (\Throwable $e) {
            throw new LlmException('OVH Image API Error: '.$e->getMessage(), 0, $e);
        }
    }

    public function getCredentialFields(): array
    {
        return [
            [
                'name' => 'api_key',
                'label' => 'API Key (Bearer Token)',
                'type' => 'password',
                'required' => true,
            ],
            [
                'name' => 'endpoint',
                'label' => 'Endpoint URL',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    /**
     * @return array{0: string, 1: string} [api_key, endpoint]
     */
    private function loadCredentials(): array
    {
        if (null === $this->providerRepository) {
            throw new \RuntimeException('OVH Image provider repository not available.');
        }

        $provider = $this->providerRepository->findByName('ovh');
        if (null === $provider) {
            throw new \RuntimeException('OVH provider not found in database. Please configure a provider named "ovh".');
        }
        if (!$provider->isEnabled()) {
            throw new \RuntimeException('OVH provider is not enabled. Please enable it in the admin panel.');
        }

        $creds = $provider->getCredentials();
        $apiKey = is_string($creds['api_key'] ?? null) ? (string) $creds['api_key'] : '';
        $endpoint = is_string($creds['endpoint'] ?? null) ? (string) $creds['endpoint'] : self::DEFAULT_ENDPOINT;

        if (null !== $this->encryptionService && $this->encryptionService->isEncrypted($apiKey)) {
            $apiKey = $this->encryptionService->decrypt($apiKey);
        }

        if ('' === $apiKey) {
            throw new \RuntimeException('OVH Image provider credentials missing api_key.');
        }

        return [$apiKey, $endpoint];
    }
}
