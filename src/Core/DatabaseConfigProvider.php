<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fournisseur de configuration dynamique depuis la BDD
 *
 * Fusionne :
 * - Le preset LLM actif (SynapsePreset)
 * - La configuration globale (SynapseConfig singleton : retention, context, system_prompt)
 * - Les credentials du provider (SynapseProvider)
 *
 * Met en cache le résultat pour éviter les requêtes répétées.
 */
class DatabaseConfigProvider implements ConfigProviderInterface
{
    /** @var string Clé de cache Symfony pour la configuration active */
    private const CACHE_KEY = 'synapse.config.active';

    /** @var int Durée du cache en secondes (5 minutes) */
    private const CACHE_TTL = 300;

    private ?array $configOverride = null;

    public function __construct(
        private SynapsePresetRepository $presetRepo,
        private SynapseConfigRepository $globalConfigRepo,
        private SynapseProviderRepository $providerRepo,
        private ?CacheInterface $cache = null,
        private ?EncryptionServiceInterface $encryptionService = null,
    ) {
    }

    /**
     * Récupère la configuration fusionnée (preset actif + config globale + credentials provider).
     *
     * Si un override est défini (via setOverride()), retourne cet override au lieu du preset actif.
     *
     * @return array Configuration formatée pour les services LLM
     */
    public function getConfig(): array
    {
        // Si un override est défini (test de preset), le retourner sans passer par le cache
        if ($this->configOverride !== null) {
            return $this->configOverride;
        }

        if ($this->cache !== null) {
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);
                return $this->loadConfig();
            });
        }

        return $this->loadConfig();
    }

    /**
     * Configure un override temporaire (en mémoire).
     *
     * Utilisé par ChatService pour tester un preset sans le rendre actif en DB.
     */
    public function setOverride(?array $config): void
    {
        $this->configOverride = $config;
    }

    /**
     * Retourne la configuration complète pour un preset spécifique,
     * sans utiliser le cache et sans modifier le preset actif.
     *
     * @return array Configuration formatée pour les services LLM
     */
    public function getConfigForPreset(SynapsePreset $preset): array
    {
        $config = $preset->toArray();

        // Load global configuration (retention, context, system_prompt)
        $globalConfig = $this->globalConfigRepo->getGlobalConfig();
        $config = array_merge($config, $globalConfig->toArray());

        // Merge provider credentials from SynapseProvider (décryptées)
        $providerName = $config['provider'];
        $provider = $this->providerRepo->findByName($providerName);

        if ($provider !== null && $provider->isEnabled()) {
            $config['provider_credentials'] = $this->decryptCredentials($provider->getCredentials());
        } else {
            $config['provider_credentials'] = [];
        }

        return $config;
    }

    /**
     * Invalide le cache de configuration
     */
    public function clearCache(): void
    {
        if ($this->cache !== null) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
     * Déchiffre les champs sensibles des credentials après la lecture depuis la base.
     *
     * Les credentials sont stockés chiffrés en base et déchiffrés lors de la lecture.
     * Le cache stocke les données déjà déchiffrées (déchiffrées une fois, mises en cache).
     */
    private function decryptCredentials(array $credentials): array
    {
        if ($this->encryptionService === null) {
            return $credentials;
        }

        foreach (['api_key', 'service_account_json', 'private_key'] as $key) {
            if (!empty($credentials[$key]) && $this->encryptionService->isEncrypted($credentials[$key])) {
                $credentials[$key] = $this->encryptionService->decrypt($credentials[$key]);
            }
        }

        return $credentials;
    }

    /**
     * Charge et fusionne la configuration depuis la BDD.
     */
    private function loadConfig(): array
    {
        // Load active preset (LLM configuration)
        $preset = $this->presetRepo->findActive();
        $config = $preset->toArray();

        // Load global configuration (retention, context, system_prompt)
        $globalConfig = $this->globalConfigRepo->getGlobalConfig();
        $config = array_merge($config, $globalConfig->toArray());

        // Merge provider credentials from SynapseProvider (décryptées)
        $providerName = $config['provider'];
        $provider = $this->providerRepo->findByName($providerName);

        if ($provider !== null && $provider->isEnabled()) {
            $config['provider_credentials'] = $this->decryptCredentials($provider->getCredentials());
        } else {
            $config['provider_credentials'] = [];
        }

        return $config;
    }
}
