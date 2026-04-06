<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun
 */
final class SynapseWorkflowRunTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $run = new SynapseWorkflowRun();

        $this->assertNull($run->getId());
        $this->assertNotEmpty($run->getWorkflowRunId());
        $this->assertNull($run->getWorkflow());
        $this->assertSame('', $run->getWorkflowKey());
        $this->assertSame(1, $run->getWorkflowVersion());
        $this->assertSame(WorkflowRunStatus::PENDING, $run->getStatus());
        $this->assertSame(0, $run->getCurrentStepIndex());
        $this->assertSame(0, $run->getStepsCount());
        $this->assertNull($run->getInput());
        $this->assertNull($run->getOutput());
        $this->assertNull($run->getErrorMessage());
        $this->assertInstanceOf(\DateTimeImmutable::class, $run->getStartedAt());
        $this->assertNull($run->getCompletedAt());
        $this->assertNull($run->getUserId());
        $this->assertNull($run->getTotalTokens());
        $this->assertNull($run->getTotalCost());
    }

    public function testConstructorGeneratesUuidV4RfcFormat(): void
    {
        $run = new SynapseWorkflowRun();
        $uuid = $run->getWorkflowRunId();

        // UUID v4 format : 8-4-4-4-12 hex chars, version 4 in 3rd group (4xxx)
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'workflowRunId must be a valid UUID v4 in RFC 4122 format'
        );
    }

    public function testEachRunHasUniqueUuid(): void
    {
        $runA = new SynapseWorkflowRun();
        $runB = new SynapseWorkflowRun();

        $this->assertNotSame($runA->getWorkflowRunId(), $runB->getWorkflowRunId());
    }

    public function testSetWorkflowDenormalizesKeyVersionAndStepsCount(): void
    {
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('doc_summary');
        $workflow->setDefinition([
            'version' => 1,
            'steps' => [
                ['name' => 'analyze', 'agent_name' => 'Analyzer'],
                ['name' => 'summarize', 'agent_name' => 'Summarizer'],
            ],
        ]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);

        $this->assertSame($workflow, $run->getWorkflow());
        $this->assertSame('doc_summary', $run->getWorkflowKey());
        $this->assertSame(1, $run->getWorkflowVersion());
        $this->assertSame(2, $run->getStepsCount());
    }

    public function testSetWorkflowNullDoesNotResetDenormalizedFields(): void
    {
        // Cas "définition supprimée après coup" : le run garde workflowKey/version/stepsCount.
        $workflow = new SynapseWorkflow();
        $workflow->setWorkflowKey('doc_summary');
        $workflow->setDefinition(['version' => 1, 'steps' => [['name' => 'a', 'agent_name' => 'A']]]);

        $run = new SynapseWorkflowRun();
        $run->setWorkflow($workflow);
        $run->setWorkflow(null);

        $this->assertNull($run->getWorkflow());
        $this->assertSame('doc_summary', $run->getWorkflowKey(), 'denormalized key must survive detachment');
        $this->assertSame(1, $run->getWorkflowVersion());
        $this->assertSame(1, $run->getStepsCount());
    }

    public function testStatusTransitions(): void
    {
        $run = new SynapseWorkflowRun();
        $this->assertSame(WorkflowRunStatus::PENDING, $run->getStatus());

        $run->setStatus(WorkflowRunStatus::RUNNING);
        $this->assertSame(WorkflowRunStatus::RUNNING, $run->getStatus());

        $run->setStatus(WorkflowRunStatus::COMPLETED);
        $this->assertSame(WorkflowRunStatus::COMPLETED, $run->getStatus());
    }

    public function testTotalCostRoundtripThroughDecimal(): void
    {
        $run = new SynapseWorkflowRun();
        $run->setTotalCost(0.123456);
        $this->assertSame(0.123456, $run->getTotalCost());

        $run->setTotalCost(null);
        $this->assertNull($run->getTotalCost());
    }

    public function testGetDurationSecondsReturnsNullWhileRunning(): void
    {
        $run = new SynapseWorkflowRun();
        $this->assertNull($run->getDurationSeconds());
    }

    public function testGetDurationSecondsWhenCompleted(): void
    {
        $run = new SynapseWorkflowRun();
        $start = new \DateTimeImmutable('2026-04-05 10:00:00');
        $end = new \DateTimeImmutable('2026-04-05 10:00:05');
        $run->setStartedAt($start);
        $run->setCompletedAt($end);

        $this->assertEqualsWithDelta(5.0, $run->getDurationSeconds(), 0.01);
    }
}
