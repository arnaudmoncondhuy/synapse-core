<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Client;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmAuthenticationException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmQuotaException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmRateLimitException;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\LlmServiceUnavailableException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Classe de base pour les clients LLM HTTP.
 *
 * Factorise le code commun entre les clients LLM :
 * - `emptyChunk()` : structure de chunk normalisé vide
 * - `handleException()` : mapping HTTP status → hiérarchie Synapse exceptions
 *
 * Les sous-classes fournissent :
 * - `getProviderName()` : identifiant interne du provider
 * - `getProviderLabel()` : préfixe humain-lisible pour les messages d'erreur
 * - `parseErrorBody()` : extraction du message d'erreur depuis la réponse HTTP du provider
 */
abstract class AbstractLlmClient implements LlmClientInterface
{
    protected const HTTP_TIMEOUT_GENERATION = 300;
    protected const HTTP_TIMEOUT_EMBEDDING = 60;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly ConfigProviderInterface $configProvider,
        protected readonly ModelCapabilityRegistry $capabilityRegistry,
    ) {
    }

    /**
     * Retourne un chunk normalisé vide (toutes les valeurs par défaut).
     *
     * @return array<string, mixed>
     */
    protected function emptyChunk(): array
    {
        return [
            'text' => null,
            'thinking' => null,
            'function_calls' => [],
            'images' => [],
            'usage' => [],
            'safety_ratings' => [],
            'blocked' => false,
            'blocked_reason' => null,
        ];
    }

    /**
     * Mappe une exception Throwable vers la hiérarchie Synapse.
     *
     * @throws LlmAuthenticationException HTTP 401/403
     * @throws LlmRateLimitException HTTP 429
     * @throws LlmServiceUnavailableException HTTP 500/503
     * @throws LlmQuotaException si "quota" dans le message
     * @throws LlmException cas par défaut
     */
    protected function handleException(\Throwable $e): never
    {
        $message = $e->getMessage();
        $statusCode = null;

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getResponse()->getStatusCode();
            try {
                $errorBody = $e->getResponse()->getContent(false);
                $message = $this->parseErrorBody($errorBody, $message);
            } catch (\Throwable) {
            }
        }

        $fullMsg = $this->getProviderLabel().' API Error: '.$message;

        throw match ($statusCode) {
            401, 403 => new LlmAuthenticationException($fullMsg, 0, $e),
            429 => new LlmRateLimitException($fullMsg, 0, $e),
            500, 503 => new LlmServiceUnavailableException($fullMsg, 0, $e),
            default => str_contains(strtolower($message), 'quota')
                ? new LlmQuotaException($fullMsg, 0, $e)
                : new LlmException($fullMsg, 0, $e),
        };
    }

    /**
     * Libellé humain du provider pour les messages d'erreur.
     * Ex: 'My Provider API'.
     */
    abstract protected function getProviderLabel(): string;

    /**
     * Extrait le message d'erreur depuis le corps de la réponse HTTP du provider.
     *
     * La valeur par défaut concatène le corps brut au message existant.
     * Les sous-classes peuvent surcharger pour analyser le JSON du provider.
     */
    protected function parseErrorBody(string $errorBody, string $originalMessage): string
    {
        return $originalMessage.' || Raw Error: '.$errorBody;
    }

    public function getIcon(): string
    {
        return 'server';
    }

    public function getDefaultCurrency(): string
    {
        return 'USD';
    }

    public function getProviderOptionsSchema(): array
    {
        return ['fields' => []];
    }

    public function validateProviderOptions(array $options, ModelCapabilities $caps): array
    {
        return $options;
    }
}
