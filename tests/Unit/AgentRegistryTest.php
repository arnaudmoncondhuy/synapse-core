<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit;

use ArnaudMoncondhuy\SynapseCore\AgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use PHPUnit\Framework\TestCase;

class AgentRegistryTest extends TestCase
{
    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function testGetReturnsAgentByKey(): void
    {
        $agent = $this->buildAgent('expert_symfony');
        $repo = $this->createStub(SynapseAgentRepository::class);
        $repo->method('findByKey')->with('expert_symfony')->willReturn($agent);

        $registry = new AgentRegistry($repo);

        $this->assertSame($agent, $registry->get('expert_symfony'));
    }

    public function testGetReturnsNullWhenAgentNotFound(): void
    {
        $repo = $this->createStub(SynapseAgentRepository::class);
        $repo->method('findByKey')->willReturn(null);

        $registry = new AgentRegistry($repo);

        $this->assertNull($registry->get('inexistant'));
    }

    // -------------------------------------------------------------------------
    // getAll()
    // -------------------------------------------------------------------------

    public function testGetAllReturnsAgentsIndexedByKey(): void
    {
        $agentA = $this->buildAgent('support');
        $agentB = $this->buildAgent('commercial');

        $repo = $this->createStub(SynapseAgentRepository::class);
        $repo->method('findAllActive')->willReturn([$agentA, $agentB]);

        $result = (new AgentRegistry($repo))->getAll();

        $this->assertArrayHasKey('support', $result);
        $this->assertArrayHasKey('commercial', $result);
    }

    public function testGetAllReturnsArrayRepresentation(): void
    {
        $agent = $this->buildAgent('support');
        $repo = $this->createStub(SynapseAgentRepository::class);
        $repo->method('findAllActive')->willReturn([$agent]);

        $result = (new AgentRegistry($repo))->getAll();

        $this->assertIsArray($result['support']);
        $this->assertArrayHasKey('key', $result['support']);
    }

    public function testGetAllReturnsEmptyArrayWhenNoActiveAgents(): void
    {
        $repo = $this->createStub(SynapseAgentRepository::class);
        $repo->method('findAllActive')->willReturn([]);

        $this->assertSame([], (new AgentRegistry($repo))->getAll());
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function buildAgent(string $key): SynapseAgent
    {
        $agent = new SynapseAgent();
        $agent->setKey($key);
        $agent->setName(ucfirst($key));
        $agent->setSystemPrompt('Tu es '.$key);
        $agent->setIsActive(true);

        return $agent;
    }
}
