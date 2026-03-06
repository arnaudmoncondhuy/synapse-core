<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use PHPUnit\Framework\TestCase;

class SynapseProviderTest extends TestCase
{
    public function testIsConfiguredFalseWhenCredentialsEmpty(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials([]);

        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfiguredFalseWhenCredentialsNotSet(): void
    {
        // Credentials default to [] when not set
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        // Don't set credentials — they default to []

        $this->assertFalse($provider->isConfigured());
    }

    public function testIsConfiguredTrueWhenCredentialsSet(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'sk-1234567890abcdef']);

        $this->assertTrue($provider->isConfigured());
    }

    public function testIsConfiguredTrueWhenCredentialsHaveMultipleKeys(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials([
            'api_key' => 'sk-xyz',
            'project_id' => 'my-project',
        ]);

        $this->assertTrue($provider->isConfigured());
    }

    public function testGetCredentialReturnsValue(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'secret-key-123']);

        $this->assertSame('secret-key-123', $provider->getCredential('api_key'));
    }

    public function testGetCredentialReturnsDefaultWhenKeyMissing(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'xyz']);

        $this->assertSame('default-value', $provider->getCredential('missing_key', 'default-value'));
    }

    public function testGetCredentialReturnsNullWhenKeyMissingAndNoDefault(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials(['api_key' => 'xyz']);

        $this->assertNull($provider->getCredential('missing_key'));
    }

    public function testGetCredentialFromEmptyCredentials(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('gemini');
        $provider->setCredentials([]);

        $this->assertNull($provider->getCredential('api_key'));
        $this->assertSame('fallback', $provider->getCredential('api_key', 'fallback'));
    }

    public function testGetCredentialReturnsZeroAsValid(): void
    {
        $provider = new SynapseProvider();
        $provider->setName('custom');
        $provider->setCredentials(['rate_limit' => 0]);

        // Zero should be a valid value, not treated as "missing"
        $this->assertSame(0, $provider->getCredential('rate_limit'));
    }
}
