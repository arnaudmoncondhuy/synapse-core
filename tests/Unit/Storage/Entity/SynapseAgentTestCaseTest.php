<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase
 */
final class SynapseAgentTestCaseTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $case = new SynapseAgentTestCase();

        $this->assertNull($case->getId());
        $this->assertNull($case->getAgent());
        $this->assertSame('', $case->getAgentKey());
        $this->assertSame('', $case->getName());
        $this->assertSame('', $case->getMessage());
        $this->assertSame([], $case->getStructuredInput());
        $this->assertSame([], $case->getAssertions());
        $this->assertSame(0, $case->getSortOrder());
        $this->assertTrue($case->isActive());
    }

    public function testSetAgentDenormalizesKey(): void
    {
        $agent = new SynapseAgent();
        $agent->setKey('support_technique');

        $case = new SynapseAgentTestCase();
        $case->setAgent($agent);

        $this->assertSame($agent, $case->getAgent());
        $this->assertSame('support_technique', $case->getAgentKey());
    }

    public function testAgentKeySurvivesAgentDeletion(): void
    {
        $agent = new SynapseAgent();
        $agent->setKey('historical_agent');

        $case = new SynapseAgentTestCase();
        $case->setAgent($agent);
        $case->setAgent(null);

        $this->assertNull($case->getAgent());
        $this->assertSame('historical_agent', $case->getAgentKey());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $case = new SynapseAgentTestCase();
        $case->setName('mot de passe oublié');
        $case->setMessage('Comment réinitialiser mon mot de passe ?');
        $case->setStructuredInput(['urgency' => 'low']);
        $case->setAssertions([
            'contains' => ['réinitialisation'],
            'max_length' => 500,
        ]);
        $case->setSortOrder(10);
        $case->setIsActive(false);

        $this->assertSame('mot de passe oublié', $case->getName());
        $this->assertSame('Comment réinitialiser mon mot de passe ?', $case->getMessage());
        $this->assertSame(['urgency' => 'low'], $case->getStructuredInput());
        $this->assertSame(['contains' => ['réinitialisation'], 'max_length' => 500], $case->getAssertions());
        $this->assertSame(10, $case->getSortOrder());
        $this->assertFalse($case->isActive());
    }
}
