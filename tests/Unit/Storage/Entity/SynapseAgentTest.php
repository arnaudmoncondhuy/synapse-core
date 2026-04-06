<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use PHPUnit\Framework\TestCase;

class SynapseAgentTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $agent = new SynapseAgent();

        $this->assertNull($agent->getId());
        $this->assertSame('', $agent->getKey());
        $this->assertSame("\u{1F916}", $agent->getEmoji());
        $this->assertSame('', $agent->getName());
        $this->assertSame('', $agent->getDescription());
        $this->assertSame('', $agent->getSystemPrompt());
        $this->assertNull($agent->getModelPreset());
        $this->assertNull($agent->getTone());
        $this->assertSame([], $agent->getAllowedToolNames());
        $this->assertSame([], $agent->getAllowedRagSources());
        $this->assertSame(5, $agent->getRagMaxResults());
        $this->assertSame(0.4, $agent->getRagMinScore());
        $this->assertTrue($agent->isBuiltin());
        $this->assertTrue($agent->isActive());
        $this->assertSame(0, $agent->getSortOrder());
        $this->assertNull($agent->getAccessControl());
        $this->assertInstanceOf(\DateTimeImmutable::class, $agent->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $agent->getUpdatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $agent = new SynapseAgent();
        $preset = new SynapseModelPreset();
        $tone = new SynapseTone();

        $agent->setKey('support')
            ->setEmoji('🤖')
            ->setName('Support Agent')
            ->setDescription('Handles support')
            ->setSystemPrompt('You are a support agent.')
            ->setModelPreset($preset)
            ->setTone($tone)
            ->setRagMaxResults(10)
            ->setRagMinScore(0.7)
            ->setIsBuiltin(false)
            ->setIsActive(false)
            ->setSortOrder(5);

        $this->assertSame('support', $agent->getKey());
        $this->assertSame('🤖', $agent->getEmoji());
        $this->assertSame('Support Agent', $agent->getName());
        $this->assertSame('Handles support', $agent->getDescription());
        $this->assertSame('You are a support agent.', $agent->getSystemPrompt());
        $this->assertSame($preset, $agent->getModelPreset());
        $this->assertSame($tone, $agent->getTone());
        $this->assertSame(10, $agent->getRagMaxResults());
        $this->assertSame(0.7, $agent->getRagMinScore());
        $this->assertFalse($agent->isBuiltin());
        $this->assertFalse($agent->isActive());
        $this->assertSame(5, $agent->getSortOrder());
    }

    public function testAllowedToolNamesFiltersNonStrings(): void
    {
        $agent = new SynapseAgent();
        $agent->setAllowedToolNames(['tool_a', '', 'tool_b']);

        $this->assertSame(['tool_a', '', 'tool_b'], $agent->getAllowedToolNames());
        $this->assertTrue($agent->hasToolRestrictions());

        $agent->setAllowedToolNames([]);
        $this->assertFalse($agent->hasToolRestrictions());
    }

    public function testAccessControlNormalization(): void
    {
        $agent = new SynapseAgent();

        $agent->setAccessControl(['roles' => ['ROLE_ADMIN'], 'userIdentifiers' => ['john@example.com']]);
        $this->assertSame(['roles' => ['ROLE_ADMIN'], 'userIdentifiers' => ['john@example.com']], $agent->getAccessControl());
        $this->assertFalse($agent->isPublic());

        // Partial input — missing userIdentifiers
        $agent->setAccessControl(['roles' => ['ROLE_TEACHER']]);
        $this->assertSame(['roles' => ['ROLE_TEACHER'], 'userIdentifiers' => []], $agent->getAccessControl());

        // Null = public
        $agent->setAccessControl(null);
        $this->assertNull($agent->getAccessControl());
        $this->assertTrue($agent->isPublic());

        // Empty arrays = public
        $agent->setAccessControl(['roles' => [], 'userIdentifiers' => []]);
        $this->assertTrue($agent->isPublic());
    }

    public function testToArray(): void
    {
        $agent = new SynapseAgent();
        $agent->setKey('test')
            ->setEmoji('📝')
            ->setName('Test')
            ->setDescription('desc')
            ->setSystemPrompt('prompt');

        $arr = $agent->toArray();

        $this->assertSame('test', $arr['key']);
        $this->assertSame('📝', $arr['emoji']);
        $this->assertSame('Test', $arr['name']);
        $this->assertNull($arr['modelPreset']);
        $this->assertNull($arr['tone']);
        $this->assertTrue($arr['isPublic']);
    }
}
