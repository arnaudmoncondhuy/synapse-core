<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PresetValidatorTest extends TestCase
{
    private SynapseProviderRepository $providerRepo;
    private ModelCapabilityRegistry $capabilityRegistry;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->providerRepo = $this->createStub(SynapseProviderRepository::class);
        $this->capabilityRegistry = $this->createStub(ModelCapabilityRegistry::class);
        $this->em = $this->createStub(EntityManagerInterface::class);
    }

    // -------------------------------------------------------------------------
    // isValid() — cas invalides
    // -------------------------------------------------------------------------

    public function testIsInvalidWhenProviderNameEmpty(): void
    {
        $preset = $this->buildPreset(providerName: '', model: 'gemini-flash', key: 'default');

        $this->assertFalse($this->buildValidator()->isValid($preset));
    }

    public function testIsInvalidWhenModelEmpty(): void
    {
        $preset = $this->buildPreset(providerName: 'gemini', model: '', key: 'default');

        $this->assertFalse($this->buildValidator()->isValid($preset));
    }

    public function testIsInvalidWhenKeyEmpty(): void
    {
        $preset = $this->buildPreset(providerName: 'gemini', model: 'gemini-flash', key: '');

        $this->assertFalse($this->buildValidator()->isValid($preset));
    }

    public function testIsInvalidWhenProviderNotFound(): void
    {
        $this->providerRepo->method('findOneBy')->willReturn(null);

        $preset = $this->buildPreset(providerName: 'inconnu', model: 'gemini-flash', key: 'default');

        $this->assertFalse($this->buildValidator()->isValid($preset));
    }

    public function testIsInvalidWhenProviderNotConfigured(): void
    {
        $provider = $this->buildProvider(configured: false);
        $this->providerRepo->method('findOneBy')->willReturn($provider);

        $preset = $this->buildPreset(providerName: 'gemini', model: 'gemini-flash', key: 'default');

        $this->assertFalse($this->buildValidator()->isValid($preset));
    }

    public function testIsInvalidWhenModelUnknown(): void
    {
        $provider = $this->buildProvider(configured: true);
        $this->providerRepo->method('findOneBy')->willReturn($provider);
        $this->capabilityRegistry->method('isKnownModel')->willReturn(false);

        $preset = $this->buildPreset(providerName: 'gemini', model: 'modele-inexistant', key: 'default');

        $this->assertFalse($this->buildValidator()->isValid($preset));
    }

    // -------------------------------------------------------------------------
    // isValid() — cas valide
    // -------------------------------------------------------------------------

    public function testIsValidWhenAllConditionsMet(): void
    {
        $provider = $this->buildProvider(configured: true);
        $this->providerRepo->method('findOneBy')->willReturn($provider);
        $this->capabilityRegistry->method('isKnownModel')->willReturn(true);

        $preset = $this->buildPreset(providerName: 'gemini', model: 'gemini-flash', key: 'default');

        $this->assertTrue($this->buildValidator()->isValid($preset));
    }

    // -------------------------------------------------------------------------
    // getInvalidReason()
    // -------------------------------------------------------------------------

    public function testReturnsNullWhenValid(): void
    {
        $provider = $this->buildProvider(configured: true);
        $this->providerRepo->method('findOneBy')->willReturn($provider);
        $this->capabilityRegistry->method('isKnownModel')->willReturn(true);

        $preset = $this->buildPreset(providerName: 'gemini', model: 'gemini-flash', key: 'default');

        $this->assertNull($this->buildValidator()->getInvalidReason($preset));
    }

    public function testReturnsReasonWhenKeyMissing(): void
    {
        $preset = $this->buildPreset(providerName: 'gemini', model: 'gemini-flash', key: '');

        $reason = $this->buildValidator()->getInvalidReason($preset);

        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('clé', $reason);
    }

    public function testReturnsReasonWhenProviderNotFound(): void
    {
        $this->providerRepo->method('findOneBy')->willReturn(null);

        $preset = $this->buildPreset(providerName: 'inconnu', model: 'gemini-flash', key: 'default');

        $reason = $this->buildValidator()->getInvalidReason($preset);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('inconnu', $reason);
    }

    public function testReturnsReasonWhenProviderNotConfigured(): void
    {
        $provider = $this->buildProvider(configured: false, label: 'Mon Provider');
        $this->providerRepo->method('findOneBy')->willReturn($provider);

        $preset = $this->buildPreset(providerName: 'gemini', model: 'gemini-flash', key: 'default');

        $reason = $this->buildValidator()->getInvalidReason($preset);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('Mon Provider', $reason);
    }

    public function testReturnsReasonWhenModelUnknown(): void
    {
        $provider = $this->buildProvider(configured: true);
        $this->providerRepo->method('findOneBy')->willReturn($provider);
        $this->capabilityRegistry->method('isKnownModel')->willReturn(false);

        $preset = $this->buildPreset(providerName: 'gemini', model: 'modele-inexistant', key: 'default');

        $reason = $this->buildValidator()->getInvalidReason($preset);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('modele-inexistant', $reason);
    }

    public function testReturnsReasonWhenAllFieldsEmpty(): void
    {
        $preset = $this->buildPreset(providerName: '', model: '', key: '');

        $reason = $this->buildValidator()->getInvalidReason($preset);

        $this->assertNotNull($reason);
        $this->assertStringContainsStringIgnoringCase('incomplète', $reason);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildValidator(): PresetValidator
    {
        return new PresetValidator($this->providerRepo, $this->capabilityRegistry, $this->em);
    }

    private function buildPreset(string $providerName, string $model, string $key): SynapseModelPreset
    {
        $preset = new SynapseModelPreset();
        $preset->setProviderName($providerName);
        $preset->setModel($model);
        $preset->setKey($key);

        return $preset;
    }

    private function buildProvider(bool $configured, string $label = 'Provider Test'): SynapseProvider
    {
        $provider = new SynapseProvider();
        $provider->setLabel($label);
        if ($configured) {
            $provider->setCredentials(['api_key' => 'secret']);
        }

        return $provider;
    }
}
