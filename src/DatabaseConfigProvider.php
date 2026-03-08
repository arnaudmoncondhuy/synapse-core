<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Fournisseur de configuration dynamique depuis la BDD.
 *
 * Fusionne :
 * - Le preset LLM actif (SynapseModelPreset)
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

    /** @var array<string, mixed>|null */
    private ?array $configOverride = null;

    public function __construct(
        private SynapseModelPresetRepository $presetRepo,
        private SynapseConfigRepository $globalConfigRepo,
        private SynapseProviderRepository $providerRepo,
        private PresetValidator $presetValidator,
        private ?CacheInterface $cache = null,
        private ?EncryptionServiceInterface $encryptionService = null,
    ) {
    }

    /**
     * Récupère la configuration fusionnée (preset actif + config globale + credentials provider).
     *
     * Si un override est défini (via setOverride()), retourne cet override au lieu du preset actif.
     *
     * @return array<string, mixed> Configuration structurée pour le client LLM
     */
    public function getConfig(): array
    {
        // Si un override est défini (test de preset), le retourner sans passer par le cache
        if (null !== $this->configOverride) {
            return $this->configOverride;
        }

        if (null !== $this->cache) {
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
     *
     * @param array<string, mixed>|null $config
     */
    public function setOverride(?array $config): void
    {
        $this->configOverride = $config;
    }

    /**
     * Retourne la configuration complète pour un preset spécifique,
     * sans utiliser le cache et sans modifier le preset actif.
     *
     * @return array<string, mixed> Configuration formatée pour les services LLM
     */
    public function getConfigForPreset(SynapseModelPreset $preset): array
    {
        $config = $preset->toArray();
        $config['preset_id'] = $preset->getId();
        $config['preset_name'] = $preset->getName();

        // Load global configuration (retention, context, system_prompt)
        $globalConfig = $this->globalConfigRepo->getGlobalConfig();
        $config = array_merge($config, $globalConfig->toArray());

        // Merge provider credentials from SynapseProvider (décryptées)
        $providerNameMixed = $config['provider'] ?? '';
        $providerName = is_string($providerNameMixed) ? $providerNameMixed : '';
        $provider = $this->providerRepo->findByName($providerName);

        if (null !== $provider && $provider->isEnabled()) {
            $config['provider_credentials'] = $this->decryptCredentials($provider->getCredentials());
        } else {
            $config['provider_credentials'] = [];
        }

        /** @var array{model: string, provider: string, provider_credentials: array<string, mixed>, safety_settings: array{enabled: bool, default_threshold: string, thresholds: array<string, string>}, generation_config: array{temperature: float, top_p: float, top_k: int, max_output_tokens: int|null, stop_sequences: array<string>}, thinking: array{enabled: bool, budget: int, reasoning_effort: string}} $finalConfig */
        $finalConfig = $config;

        return $finalConfig;
    }

    /**
     * Invalide le cache de configuration.
     */
    public function clearCache(): void
    {
        if (null !== $this->cache) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
     * Déchiffre les champs sensibles des credentials après la lecture depuis la base.
     *
     * Les credentials sont stockés chiffrés en base et déchiffrés lors de la lecture.
     * Le cache stocke les données déjà déchiffrées (déchiffrées une fois, mises en cache).
     *
     * @param array<string, mixed> $credentials
     *
     * @return array<string, mixed>
     */
    private function decryptCredentials(array $credentials): array
    {
        if (null === $this->encryptionService) {
            return $credentials;
        }

        foreach (['api_key', 'service_account_json', 'private_key'] as $key) {
            $val = $credentials[$key] ?? null;
            if (is_string($val) && $this->encryptionService->isEncrypted($val)) {
                $credentials[$key] = $this->encryptionService->decrypt($val);
            }
        }

        return $credentials;
    }

    /**
     * Charge et fusionne la configuration depuis la BDD.
     *
     * Si aucun preset valide n'existe, retourne une configuration par défaut
     * (pour permettre la création du premier preset).
     *
     * @return array<string, mixed>
     */
    private function loadConfig(): array
    {
        // Load active preset (LLM configuration)
        $preset = $this->presetRepo->findActive();

        // 🛡️ DÉFENSE CRITIQUE : Vérifier l'intégrité du preset actif
        // Si le preset actif est devenu invalide (provider désactivé, etc.),
        // le désactiver automatiquement et chercher un autre
        try {
            $this->presetValidator->ensureActivePresetIsValid($preset);
        } catch (\Exception $e) {
            // Fallback : retourner config par défaut au lieu de lever exception
            return $this->getDefaultConfig();
        }

        $config = $preset->toArray();
        $config['preset_id'] = $preset->getId();
        $config['preset_name'] = $preset->getName();

        // Load global configuration (retention, context, system_prompt)
        $globalConfig = $this->globalConfigRepo->getGlobalConfig();
        $config = array_merge($config, $globalConfig->toArray());

        // Merge provider credentials from SynapseProvider (décryptées)
        $providerNameMixed = $config['provider'] ?? '';
        $providerName = is_string($providerNameMixed) ? $providerNameMixed : '';
        $provider = $this->providerRepo->findByName($providerName);

        if (null !== $provider && $provider->isEnabled()) {
            $config['provider_credentials'] = $this->decryptCredentials($provider->getCredentials());
        } else {
            $config['provider_credentials'] = [];
        }

        /** @var array{model: string, provider: string, provider_credentials: array<string, mixed>, safety_settings: array{enabled: bool, default_threshold: string, thresholds: array<string, string>}, generation_config: array{temperature: float, top_p: float, top_k: int, max_output_tokens: int|null, stop_sequences: array<string>}, thinking: array{enabled: bool, budget: int, reasoning_effort: string}} $finalConfig */
        $finalConfig = $config;

        return $finalConfig;
    }

    /**
     * Configuration par défaut safe quand aucun preset valide n'existe.
     * Permet la création du premier preset sans erreur.
     *
     * @return array<string, mixed>
     */
    private function getDefaultConfig(): array
    {
        // Config minimale safe — utilise Gemini comme provider par défaut
        $config = [
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'provider_name' => 'gemini',
            'provider_credentials' => [],
            'preset_id' => null,
        ];

        // Ajouter la config globale si elle existe
        try {
            $globalConfig = $this->globalConfigRepo->getGlobalConfig();
            $config = array_merge($config, $globalConfig->toArray());
        } catch (\Exception) {
            // Si pas de global config, utiliser les valeurs par défaut
        }

        return $config;
    }
}
