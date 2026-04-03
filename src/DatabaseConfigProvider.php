<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseFallbackActivatedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
#[AsAlias(id: ConfigProviderInterface::class)]
class DatabaseConfigProvider implements ConfigProviderInterface
{
    private const CACHE_KEY = 'synapse.config.active';
    private const CACHE_TTL = 300;

    private ?SynapseRuntimeConfig $configOverride = null;

    public function __construct(
        private readonly SynapseModelPresetRepository $presetRepo,
        private readonly SynapseConfigRepository $globalConfigRepo,
        private readonly SynapseProviderRepository $providerRepo,
        private readonly PresetValidator $presetValidator,
        private readonly ?CacheInterface $cache = null,
        private readonly ?EncryptionServiceInterface $encryptionService = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public function getConfig(): SynapseRuntimeConfig
    {
        if (null !== $this->configOverride) {
            return $this->configOverride;
        }

        if (null !== $this->cache) {
            /* @var SynapseRuntimeConfig */
            return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
                $item->expiresAfter(self::CACHE_TTL);

                return $this->loadConfig();
            });
        }

        return $this->loadConfig();
    }

    public function setOverride(?SynapseRuntimeConfig $config): void
    {
        $this->configOverride = $config;
    }

    public function getConfigForPreset(SynapseModelPreset $preset): SynapseRuntimeConfig
    {
        $raw = $preset->toArray();
        $raw['preset_id'] = $preset->getId();
        $raw['preset_name'] = $preset->getName();

        $globalConfig = $this->globalConfigRepo->getGlobalConfig();
        $raw = array_merge($globalConfig->toArray(), $raw);

        $providerName = is_string($raw['provider'] ?? null) ? (string) $raw['provider'] : '';
        $provider = $this->providerRepo->findByName($providerName);

        $raw['provider_credentials'] = (null !== $provider && $provider->isEnabled())
            ? $this->decryptCredentials($provider->getCredentials())
            : [];

        return SynapseRuntimeConfig::fromArray($raw);
    }

    public function clearCache(): void
    {
        if (null !== $this->cache) {
            $this->cache->delete(self::CACHE_KEY);
        }
    }

    /**
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

    private function loadConfig(): SynapseRuntimeConfig
    {
        $preset = $this->presetRepo->findActive();

        try {
            $this->presetValidator->ensureActivePresetIsValid($preset);
        } catch (\Exception $e) {
            $this->logger?->warning('Synapse: preset invalide, fallback sur config par défaut.', [
                'reason' => $e->getMessage(),
            ]);
            $this->dispatcher?->dispatch(new SynapseFallbackActivatedEvent(
                component: 'config',
                reason: $e->getMessage(),
                exception: $e,
            ));

            return $this->getDefaultConfig();
        }

        $raw = $preset->toArray();
        $raw['preset_id'] = $preset->getId();
        $raw['preset_name'] = $preset->getName();

        $globalConfig = $this->globalConfigRepo->getGlobalConfig();
        $raw = array_merge($globalConfig->toArray(), $raw);

        $providerName = is_string($raw['provider'] ?? null) ? (string) $raw['provider'] : '';
        $provider = $this->providerRepo->findByName($providerName);

        $raw['provider_credentials'] = (null !== $provider && $provider->isEnabled())
            ? $this->decryptCredentials($provider->getCredentials())
            : [];

        return SynapseRuntimeConfig::fromArray($raw);
    }

    private function getDefaultConfig(): SynapseRuntimeConfig
    {
        $raw = [
            'provider' => '',
            'model' => '',
            'provider_credentials' => [],
            'preset_id' => null,
        ];

        try {
            $globalConfig = $this->globalConfigRepo->getGlobalConfig();
            $raw = array_merge($raw, $globalConfig->toArray());
        } catch (\Exception $e) {
            $this->logger?->warning('Synapse: impossible de charger la config globale.', [
                'reason' => $e->getMessage(),
            ]);
        }

        return SynapseRuntimeConfig::fromArray($raw);
    }
}
