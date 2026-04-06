<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion
 */
final class SynapseAgentPromptVersionTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $version = new SynapseAgentPromptVersion();

        $this->assertNull($version->getId());
        $this->assertNull($version->getAgent());
        $this->assertSame('', $version->getAgentKey());
        $this->assertSame('', $version->getSystemPrompt());
        $this->assertSame('system:unknown', $version->getChangedBy());
        $this->assertNull($version->getReason());
        $this->assertFalse($version->isActive());
        $this->assertInstanceOf(\DateTimeImmutable::class, $version->getCreatedAt());
    }

    public function testSetAgentDenormalizesAgentKey(): void
    {
        $agent = new SynapseAgent();
        $agent->setKey('support_agent');

        $version = new SynapseAgentPromptVersion();
        $version->setAgent($agent);

        $this->assertSame($agent, $version->getAgent());
        $this->assertSame('support_agent', $version->getAgentKey());
    }

    public function testSetAgentToNullDoesNotClearDenormalizedKey(): void
    {
        // Simule le cas d'une suppression de l'agent parent : la relation
        // passe à null (via onDelete SET NULL) mais l'agentKey dénormalisé
        // doit survivre pour préserver l'historique.
        $agent = new SynapseAgent();
        $agent->setKey('historical');

        $version = new SynapseAgentPromptVersion();
        $version->setAgent($agent);
        $this->assertSame('historical', $version->getAgentKey());

        $version->setAgent(null);
        $this->assertNull($version->getAgent());
        $this->assertSame('historical', $version->getAgentKey());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $version = new SynapseAgentPromptVersion();
        $version->setSystemPrompt('Tu es un assistant francophone.');
        $version->setChangedBy('human:admin');
        $version->setReason('Ajout de la directive francophone');
        $version->setIsActive(true);
        $version->setAgentKey('custom_key');

        $this->assertSame('Tu es un assistant francophone.', $version->getSystemPrompt());
        $this->assertSame('human:admin', $version->getChangedBy());
        $this->assertSame('Ajout de la directive francophone', $version->getReason());
        $this->assertTrue($version->isActive());
        $this->assertSame('custom_key', $version->getAgentKey());
    }
}
