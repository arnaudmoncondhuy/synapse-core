<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentTestResult;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentTestRunner;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentTestCaseRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\AgentTestRunner
 */
final class AgentTestRunnerTest extends TestCase
{
    public function testRunCasePassesWhenAllAssertionsMatch(): void
    {
        $agent = $this->makeAgent('support');
        $case = new SynapseAgentTestCase();
        $case->setAgent($agent);
        $case->setName('mot de passe');
        $case->setMessage('Comment réinitialiser mon mot de passe ?');
        $case->setAssertions([
            'contains' => ['réinitialisation', 'compte'],
            'not_contains' => ['impossible'],
            'min_length' => 5,
            'max_length' => 500,
        ]);

        $agentDouble = $this->makeAgentDouble('Pour la réinitialisation du mot de passe, cliquez sur votre compte.');
        $resolver = $this->makeResolver($agentDouble);
        $repository = $this->createStub(SynapseAgentTestCaseRepository::class);

        $runner = new AgentTestRunner($resolver, $repository);
        $result = $runner->runCase($case);

        $this->assertTrue($result->isPassed(), 'expected all assertions to pass');
        $this->assertSame(AgentTestResult::STATUS_PASSED, $result->status);
        $this->assertCount(5, $result->assertionResults);
        $this->assertSame(0, $result->failedAssertionsCount());
    }

    public function testRunCaseFailsWhenContainsMissing(): void
    {
        $agent = $this->makeAgent('support');
        $case = new SynapseAgentTestCase();
        $case->setAgent($agent);
        $case->setName('routine');
        $case->setMessage('hi');
        $case->setAssertions([
            'contains' => ['absent_needle'],
        ]);

        $agentDouble = $this->makeAgentDouble('Hello world');
        $resolver = $this->makeResolver($agentDouble);
        $repository = $this->createStub(SynapseAgentTestCaseRepository::class);

        $runner = new AgentTestRunner($resolver, $repository);
        $result = $runner->runCase($case);

        $this->assertTrue($result->isFailed());
        $this->assertSame(1, $result->failedAssertionsCount());
        $this->assertSame('Hello world', $result->answer);
    }

    public function testRunCaseFailsWhenNotContainsViolated(): void
    {
        $agent = $this->makeAgent('support');
        $case = new SynapseAgentTestCase();
        $case->setAgent($agent);
        $case->setName('no profanity');
        $case->setMessage('hi');
        $case->setAssertions([
            'not_contains' => ['TERRIBLE'],
        ]);

        $agentDouble = $this->makeAgentDouble('This is terrible content.');
        $resolver = $this->makeResolver($agentDouble);
        $repository = $this->createStub(SynapseAgentTestCaseRepository::class);

        $runner = new AgentTestRunner($resolver, $repository);
        $result = $runner->runCase($case);

        $this->assertTrue($result->isFailed());
        $this->assertSame(1, $result->failedAssertionsCount());
    }

    public function testRunCaseErrorsWhenAgentThrows(): void
    {
        $agent = $this->makeAgent('support');
        $case = new SynapseAgentTestCase();
        $case->setAgent($agent);
        $case->setName('boom');
        $case->setMessage('hi');

        $throwingAgent = new class implements AgentInterface {
            public function getName(): string
            {
                return 'throwing_agent';
            }

            public function getLabel(): string
            {
                return 'throwing_agent';
            }

            public function getDescription(): string
            {
                return 'test double that throws';
            }

            public function call(Input $input, array $options = []): Output
            {
                throw new \RuntimeException('LLM timed out');
            }
        };

        $resolver = $this->makeResolver($throwingAgent);
        $repository = $this->createStub(SynapseAgentTestCaseRepository::class);

        $runner = new AgentTestRunner($resolver, $repository);
        $result = $runner->runCase($case);

        $this->assertTrue($result->isError());
        $this->assertSame('LLM timed out', $result->errorMessage);
    }

    public function testRunCaseErrorsWhenOrphaned(): void
    {
        // Test case dont l'agent parent a été supprimé (FK nullable → SET NULL).
        $case = new SynapseAgentTestCase();
        $case->setName('orphan');
        $case->setAgentKey('deleted_agent');

        $resolver = $this->makeResolver($this->makeAgentDouble('irrelevant'));
        $repository = $this->createStub(SynapseAgentTestCaseRepository::class);

        $runner = new AgentTestRunner($resolver, $repository);
        $result = $runner->runCase($case);

        $this->assertTrue($result->isError());
        $this->assertNotNull($result->errorMessage);
        $this->assertStringContainsString('orphaned', $result->errorMessage);
    }

    public function testRunSuiteAggregatesResults(): void
    {
        $agent = $this->makeAgent('support');

        $passingCase = new SynapseAgentTestCase();
        $passingCase->setAgent($agent);
        $passingCase->setName('pass');
        $passingCase->setMessage('hi');
        $passingCase->setAssertions(['contains' => ['world']]);

        $failingCase = new SynapseAgentTestCase();
        $failingCase->setAgent($agent);
        $failingCase->setName('fail');
        $failingCase->setMessage('hi');
        $failingCase->setAssertions(['contains' => ['not_here']]);

        $agentDouble = $this->makeAgentDouble('Hello world');
        $resolver = $this->makeResolver($agentDouble);

        $repository = $this->createStub(SynapseAgentTestCaseRepository::class);
        $repository->method('findActiveForAgent')->willReturn([$passingCase, $failingCase]);

        $runner = new AgentTestRunner($resolver, $repository);
        $results = $runner->runSuite($agent);

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isPassed());
        $this->assertTrue($results[1]->isFailed());
    }

    private function makeAgent(string $key): SynapseAgent
    {
        $agent = new SynapseAgent();
        $agent->setKey($key);
        $agent->setName($key);

        return $agent;
    }

    private function makeAgentDouble(string $answer): AgentInterface
    {
        return new class($answer) implements AgentInterface {
            public function __construct(private readonly string $answer)
            {
            }

            public function getName(): string
            {
                return 'agent_double';
            }

            public function getLabel(): string
            {
                return 'agent_double';
            }

            public function getDescription(): string
            {
                return 'test double returning a canned answer';
            }

            public function call(Input $input, array $options = []): Output
            {
                return new Output(answer: $this->answer, usage: ['total_tokens' => 42]);
            }
        };
    }

    private function makeResolver(AgentInterface $agentDouble): AgentResolver
    {
        // On injecte un resolver qui retourne toujours le même agent double.
        return new class($agentDouble) extends AgentResolver {
            public function __construct(private readonly AgentInterface $double)
            {
                // ne pas appeler parent::__construct — on override tout ce qui est utilisé.
            }

            public function createRootContext(?string $userId = null, ?int $budgetTokensRemaining = null, string $origin = 'direct'): AgentContext
            {
                return AgentContext::root(userId: $userId, origin: $origin);
            }

            public function resolve(string $name, AgentContext $context): AgentInterface
            {
                return $this->double;
            }

            public function getMaxDepth(): int
            {
                return 2;
            }
        };
    }
}
