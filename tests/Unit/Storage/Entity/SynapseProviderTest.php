<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use PHPUnit\Framework\TestCase;

class SynapseProviderTest extends TestCase
{
    public function testIsConfigured_falseWhenCredentialsEmpty(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials([]);

        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfigured_falseWhenCredentialsNotSet(): void
    {
        // Credentials default to [] when not set
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        // Don't set credentials — they default to []

        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfigured_trueWhenCredentialsSet(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'sk-1234567890abcdef']);

        $this->assertTrue($provider->isConfigured());
    }

    public function testIsConfigured_trueWhenCredentialsHaveMultipleKeys(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials([
            'api_key' => 'sk-xyz',
            'project_id' => 'my-project',
        ]);

        $this->assertTrue($provider->isConfigured());
    }

    public function testGetCredential_returnsValue(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'secret-key-123']);

        $this->assertSame('secret-key-123', $provider->getCredential('api_key'));
    }

    public function testGetCredential_returnsDefault_whenKeyMissing(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'xyz']);

        $this->assertSame('default-value', $provider->getCredential('missing_key', 'default-value'));
    }

    public function testGetCredential_returnsNull_whenKeyMissingAndNoDefault(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'xyz']);

        $this->assertNull($provider->getCredential('missing_key'));
    }

    public function testGetCredential_fromEmptyCredentials(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials([]);

        $this->assertNull($provider->getCredential('api_key'));
        $this->assertSame('fallback', $provider->getCredential('api_key', 'fallback'));
    }

    public function testGetCredential_returnsZeroAsValid(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('custom');
        $provider->setCredentials(['rate_limit' => 0]);

        // Zero should be a valid value, not treated as "missing"
        $this->assertSame(0, $provider->getCredential('rate_limit'));
    }
}
