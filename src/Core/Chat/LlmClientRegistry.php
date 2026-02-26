<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;

/**
 * Registre des clients LLM disponibles.
 *
 * Sélectionne le bon client en fonction du provider configuré en DB (ou YAML par défaut).
 * Les clients sont enregistrés automatiquement via le tag Symfony `synapse.llm_client`.
 */
class LlmClientRegistry
{
    /** @var LlmClientInterface[] Indexed by provider name */
    private array $clientMap = [];

    /**
     * @param iterable<LlmClientInterface> $clients       Clients tagués `synapse.llm_client`
     * @param ConfigProviderInterface      $configProvider Fournisseur de config DB
     * @param string                       $defaultProvider Provider YAML par défaut (bootstrap)
     */
    public function __construct(
        iterable $clients,
        private ConfigProviderInterface $configProvider,
        private string $defaultProvider = 'gemini',
    ) {
        foreach ($clients as $client) {
            $this->clientMap[$client->getProviderName()] = $client;
        }
    }

    /**
     * Retourne le client LLM actif selon la configuration DB, avec fallback YAML.
     *
     * @throws \RuntimeException Si le provider configuré n'est pas disponible.
     */
    public function getClient(): LlmClientInterface
    {
        $config = $this->configProvider->getConfig();
        $providerName = $config['provider'] ?? $this->defaultProvider;

        return $this->getClientByProvider($providerName);
    }

    /**
     * Retourne un client LLM spécifique par son nom de provider.
     */
    public function getClientByProvider(string $providerName): LlmClientInterface
    {
        if (isset($this->clientMap[$providerName])) {
            return $this->clientMap[$providerName];
        }

        throw new \RuntimeException(sprintf('Provider LLM "%s" non disponible.', $providerName));
    }

    /**
     * Liste tous les providers disponibles.
     *
     * @return string[]
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->clientMap);
    }
}
