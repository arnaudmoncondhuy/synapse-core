<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\DatabaseConfigProvider;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class DatabaseConfigProviderTest extends TestCase
{
    private SynapseModelPresetRepository $presetRepo;
    private SynapseConfigRepository $globalConfigRepo;
    private SynapseProviderRepository $providerRepo;

    protected function setUp(): void
    {
        $this->presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $this->globalConfigRepo = $this->createStub(SynapseConfigRepository::class);
        $this->providerRepo = $this->createStub(SynapseProviderRepository::class);

        $this->globalConfigRepo->method('getGlobalConfig')->willReturn(new SynapseConfig());
        $this->providerRepo->method('findByName')->willReturn(null);
    }

    /**
     * Construit un PresetValidator qui considère tous les presets comme valides.
     */
    private function buildPassingValidator(): PresetValidator
    {
        $validatorProviderRepo = $this->createStub(SynapseProviderRepository::class);
        $provider = new SynapseProvider();
        $provider->setLabel('Gemini');
        $provider->setCredentials(['api_key' => 'key']);
        $validatorProviderRepo->method('findOneBy')->willReturn($provider);

        $capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('isKnownModel')->willReturn(true);

        $em = $this->createStub(EntityManagerInterface::class);

        return new PresetValidator($validatorProviderRepo, $capabilityRegistry, $em);
    }

    /**
     * Construit un PresetValidator qui considère tous les presets comme invalides.
     */
    private function buildFailingValidator(): PresetValidator
    {
        $validatorProviderRepo = $this->createStub(SynapseProviderRepository::class);
        $validatorProviderRepo->method('findOneBy')->willReturn(null); // provider introuvable

        $capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $capabilityRegistry->method('isKnownModel')->willReturn(false);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($this->createStub(\Doctrine\ORM\EntityRepository::class));

        return new PresetValidator($validatorProviderRepo, $capabilityRegistry, $em);
    }

    // -------------------------------------------------------------------------
    // setOverride — retour immédiat sans DB
    // -------------------------------------------------------------------------

    public function testGetConfigReturnsOverrideWhenSet(): void
    {
        $override = SynapseRuntimeConfig::fromArray(['model' => 'override-model', 'provider' => 'test']);
        $configProvider = $this->buildProvider($this->buildPassingValidator());

        $configProvider->setOverride($override);

        $this->assertSame('override-model', $configProvider->getConfig()->model);
    }

    public function testGetConfigCallsDbWhenNoOverride(): void
    {
        $preset = $this->buildPreset();
        $this->presetRepo->method('findActive')->willReturn($preset);

        $config = $this->buildProvider($this->buildPassingValidator())->getConfig();

        $this->assertNotEmpty($config->model);
    }

    public function testOverrideCanBeCleared(): void
    {
        $preset = $this->buildPreset();
        $this->presetRepo->method('findActive')->willReturn($preset);

        $configProvider = $this->buildProvider($this->buildPassingValidator());
        $configProvider->setOverride(SynapseRuntimeConfig::fromArray(['model' => 'override']));
        $configProvider->setOverride(null);

        $config = $configProvider->getConfig();
        $this->assertNotSame('override', $config->model);
    }

    // -------------------------------------------------------------------------
    // Fallback config par défaut
    // -------------------------------------------------------------------------

    public function testReturnsDefaultConfigWhenPresetValidatorThrows(): void
    {
        $preset = $this->buildPreset();
        $this->presetRepo->method('findActive')->willReturn($preset);

        // Validator qui échoue → fallback sur config par défaut
        $config = $this->buildProvider($this->buildFailingValidator())->getConfig();

        // Fallback returns empty provider/model — resolved dynamically by LlmClientRegistry
        $this->assertSame('', $config->provider);
        $this->assertSame('', $config->model);
        $this->assertSame([], $config->providerCredentials);
    }

    // -------------------------------------------------------------------------
    // getConfigForPreset
    // -------------------------------------------------------------------------

    public function testGetConfigForPresetReturnsPresetData(): void
    {
        $preset = $this->buildPreset(model: 'gemini-pro');

        $config = $this->buildProvider($this->buildPassingValidator())->getConfigForPreset($preset);

        $this->assertSame('gemini-pro', $config->model);
    }

    public function testGetConfigForPresetIncludesGlobalConfig(): void
    {
        $globalConfig = new SynapseConfig();
        $globalConfig->setSystemPrompt('Prompt global');

        $globalConfigRepo = $this->createStub(SynapseConfigRepository::class);
        $globalConfigRepo->method('getGlobalConfig')->willReturn($globalConfig);

        $provider = new DatabaseConfigProvider(
            presetRepo: $this->presetRepo,
            globalConfigRepo: $globalConfigRepo,
            providerRepo: $this->providerRepo,
            presetValidator: $this->buildPassingValidator(),
        );

        $config = $provider->getConfigForPreset($this->buildPreset());

        $this->assertSame('Prompt global', $config->systemPrompt);
    }

    public function testGetConfigForPresetIncludesProviderCredentials(): void
    {
        $synapseProvider = new SynapseProvider();
        $synapseProvider->setLabel('Gemini');
        $synapseProvider->setIsEnabled(true);
        $synapseProvider->setCredentials(['api_key' => 'my-secret-key']);

        $config = $this->buildProvider($this->buildPassingValidator(), providerForRepo: $synapseProvider)
            ->getConfigForPreset($this->buildPreset());

        $this->assertSame('my-secret-key', $config->providerCredentials['api_key']);
    }

    public function testGetConfigForPresetReturnsEmptyCredentialsWhenProviderDisabled(): void
    {
        $synapseProvider = new SynapseProvider();
        $synapseProvider->setLabel('Gemini');
        $synapseProvider->setIsEnabled(false);
        $synapseProvider->setCredentials(['api_key' => 'key']);

        $config = $this->buildProvider($this->buildPassingValidator(), providerForRepo: $synapseProvider)
            ->getConfigForPreset($this->buildPreset());

        $this->assertSame([], $config->providerCredentials);
    }

    // -------------------------------------------------------------------------
    // Déchiffrement des credentials
    // -------------------------------------------------------------------------

    public function testDecryptsApiKeyWhenEncrypted(): void
    {
        $encryption = $this->createStub(EncryptionServiceInterface::class);
        $encryption->method('isEncrypted')->willReturn(true);
        $encryption->method('decrypt')->willReturn('decrypted-key');

        $synapseProvider = new SynapseProvider();
        $synapseProvider->setLabel('Gemini');
        $synapseProvider->setIsEnabled(true);
        $synapseProvider->setCredentials(['api_key' => 'encrypted-value']);

        $config = $this->buildProvider($this->buildPassingValidator(), $encryption, $synapseProvider)
            ->getConfigForPreset($this->buildPreset());

        $this->assertSame('decrypted-key', $config->providerCredentials['api_key']);
    }

    public function testDoesNotDecryptWhenNotEncrypted(): void
    {
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encryption->method('isEncrypted')->willReturn(false);
        $encryption->expects($this->never())->method('decrypt');

        $synapseProvider = new SynapseProvider();
        $synapseProvider->setLabel('Gemini');
        $synapseProvider->setIsEnabled(true);
        $synapseProvider->setCredentials(['api_key' => 'plain-key']);

        $config = $this->buildProvider($this->buildPassingValidator(), $encryption, $synapseProvider)
            ->getConfigForPreset($this->buildPreset());

        $this->assertSame('plain-key', $config->providerCredentials['api_key']);
    }

    public function testPassesThroughCredentialsWithoutEncryptionService(): void
    {
        $synapseProvider = new SynapseProvider();
        $synapseProvider->setLabel('Gemini');
        $synapseProvider->setIsEnabled(true);
        $synapseProvider->setCredentials(['api_key' => 'plain-key']);

        $config = $this->buildProvider($this->buildPassingValidator(), null, $synapseProvider)
            ->getConfigForPreset($this->buildPreset());

        $this->assertSame('plain-key', $config->providerCredentials['api_key']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildProvider(
        PresetValidator $presetValidator,
        ?EncryptionServiceInterface $encryption = null,
        ?SynapseProvider $providerForRepo = null,
    ): DatabaseConfigProvider {
        $providerRepo = $this->providerRepo;
        if (null !== $providerForRepo) {
            $providerRepo = $this->createStub(SynapseProviderRepository::class);
            $providerRepo->method('findByName')->willReturn($providerForRepo);
        }

        return new DatabaseConfigProvider(
            presetRepo: $this->presetRepo,
            globalConfigRepo: $this->globalConfigRepo,
            providerRepo: $providerRepo,
            presetValidator: $presetValidator,
            cache: null,
            encryptionService: $encryption,
        );
    }

    private function buildPreset(string $model = 'gemini-flash'): SynapseModelPreset
    {
        $preset = new SynapseModelPreset();
        $preset->setKey('default');
        $preset->setName('Default');
        $preset->setModel($model);
        $preset->setProviderName('gemini');
        $preset->setIsActive(true);

        return $preset;
    }
}
