<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance;

use ArnaudMoncondhuy\SynapseCore\Contract\PromptJudgeInterface;
use ArnaudMoncondhuy\SynapseCore\Governance\NullPromptJudge;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptJudgment;
use ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\PromptVersionStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentPromptVersionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\PromptVersionRecorder
 */
final class PromptVersionRecorderTest extends TestCase
{
    public function testSnapshotCreatesFirstVersionWhenHistoryEmpty(): void
    {
        $agent = $this->makeAgent('support');

        $repository = $this->createMock(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->with($agent)->willReturn(null);
        $repository->method('findActiveForAgent')->with($agent)->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (SynapseAgentPromptVersion $v) use ($agent): bool {
                return 'Hello world' === $v->getSystemPrompt()
                    && 'human:admin' === $v->getChangedBy()
                    && 'initial' === $v->getReason()
                    && true === $v->isActive()
                    && $agent === $v->getAgent()
                    && 'support' === $v->getAgentKey();
            }));
        $em->expects($this->never())->method('flush');

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $version = $recorder->snapshot($agent, 'Hello world', 'human:admin', 'initial');

        $this->assertNotNull($version);
        $this->assertTrue($version->isActive());
    }

    public function testSnapshotDeactivatesPreviousActiveVersion(): void
    {
        $agent = $this->makeAgent('support');

        $previous = new SynapseAgentPromptVersion();
        $previous->setAgent($agent);
        $previous->setSystemPrompt('Old prompt');
        $previous->setIsActive(true);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn($previous);
        $repository->method('findActiveForAgent')->willReturn($previous);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $version = $recorder->snapshot($agent, 'New prompt', 'human:admin');

        $this->assertNotNull($version);
        $this->assertFalse($previous->isActive(), 'previous version must be deactivated');
        $this->assertTrue($version->isActive());
        $this->assertSame('New prompt', $version->getSystemPrompt());
    }

    public function testSnapshotIsIdempotentWhenPromptUnchanged(): void
    {
        // Save du formulaire admin sans modification réelle du prompt :
        // aucune nouvelle version ne doit être créée (anti-pollution).
        $agent = $this->makeAgent('support');

        $latest = new SynapseAgentPromptVersion();
        $latest->setAgent($agent);
        $latest->setSystemPrompt('Hello world');
        $latest->setIsActive(true);

        $repository = $this->createMock(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn($latest);
        // findActiveForAgent ne doit PAS être appelé puisqu'on court-circuite
        // dès l'idempotence (latest est déjà active).
        $repository->expects($this->never())->method('findActiveForAgent');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $result = $recorder->snapshot($agent, 'Hello world', 'human:admin');

        $this->assertNull($result);
    }

    public function testSnapshotIdempotentRepairsInactiveLatest(): void
    {
        // Cas edge : la dernière version porte déjà le prompt demandé mais
        // elle n'est pas marquée active (état incohérent hérité d'avant le
        // garde-fou). Le recorder doit la remettre active sans créer de doublon.
        $agent = $this->makeAgent('support');

        $latest = new SynapseAgentPromptVersion();
        $latest->setAgent($agent);
        $latest->setSystemPrompt('Hello world');
        $latest->setIsActive(false);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn($latest);
        $repository->method('findActiveForAgent')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $result = $recorder->snapshot($agent, 'Hello world', 'human:admin');

        $this->assertNull($result, 'no new snapshot on idempotent call');
        $this->assertTrue($latest->isActive(), 'incoherent state must be repaired');
    }

    public function testSnapshotFlushesWhenRequested(): void
    {
        $agent = $this->makeAgent('support');

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn(null);
        $repository->method('findActiveForAgent')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist');
        $em->expects($this->once())->method('flush');

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $recorder->snapshot($agent, 'prompt', 'mcp:claude', null, true);
    }

    public function testSnapshotRecordsJudgmentWhenJudgeReturnsVerdict(): void
    {
        $agent = $this->makeAgent('support');

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn(null);
        $repository->method('findActiveForAgent')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (SynapseAgentPromptVersion $v): bool {
                return 8.5 === $v->getJudgmentScore()
                    && 'looks good' === $v->getJudgmentRationale()
                    && ['overall_score' => 8.5, 'rationale' => 'looks good'] === $v->getJudgmentData()
                    && 'model:gemini/flash' === $v->getJudgedBy()
                    && $v->getJudgedAt() instanceof \DateTimeImmutable;
            }));

        $judge = new class implements PromptJudgeInterface {
            public function judge(SynapseAgent $agent, string $newPrompt, ?string $previousPrompt): ?PromptJudgment
            {
                return new PromptJudgment(
                    score: 8.5,
                    rationale: 'looks good',
                    data: ['overall_score' => 8.5, 'rationale' => 'looks good'],
                    judgedBy: 'model:gemini/flash',
                );
            }
        };

        $recorder = new PromptVersionRecorder($em, $repository, $judge);
        $recorder->snapshot($agent, 'new prompt', 'human:admin');
    }

    public function testSnapshotSkipsJudgmentWhenJudgeReturnsNull(): void
    {
        $agent = $this->makeAgent('support');

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn(null);
        $repository->method('findActiveForAgent')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (SynapseAgentPromptVersion $v): bool {
                return false === $v->hasJudgment()
                    && null === $v->getJudgmentScore()
                    && null === $v->getJudgedAt();
            }));

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $recorder->snapshot($agent, 'new prompt', 'human:admin');
    }

    public function testSnapshotPassesPreviousPromptToJudgeForDiffEvaluation(): void
    {
        $agent = $this->makeAgent('support');

        $previous = new SynapseAgentPromptVersion();
        $previous->setAgent($agent);
        $previous->setSystemPrompt('Old prompt');
        $previous->setIsActive(true);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn($previous);
        $repository->method('findActiveForAgent')->willReturn($previous);

        $em = $this->createStub(EntityManagerInterface::class);

        $capturedPrevious = null;
        $judge = new class($capturedPrevious) implements PromptJudgeInterface {
            public function __construct(private ?string &$capturedPrevious)
            {
            }

            public function judge(SynapseAgent $agent, string $newPrompt, ?string $previousPrompt): ?PromptJudgment
            {
                $this->capturedPrevious = $previousPrompt;

                return null;
            }
        };

        $recorder = new PromptVersionRecorder($em, $repository, $judge);
        $recorder->snapshot($agent, 'New prompt', 'human:admin');

        $this->assertSame('Old prompt', $capturedPrevious);
    }

    public function testMarkActiveDeactivatesPrevious(): void
    {
        $agent = $this->makeAgent('support');

        $previous = new SynapseAgentPromptVersion();
        $previous->setAgent($agent);
        $previous->setIsActive(true);
        $this->setId($previous, 1);

        $target = new SynapseAgentPromptVersion();
        $target->setAgent($agent);
        $target->setIsActive(false);
        $this->setId($target, 2);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findActiveForAgent')->willReturn($previous);

        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $recorder->markActive($agent, $target);

        $this->assertFalse($previous->isActive());
        $this->assertTrue($target->isActive());
    }

    public function testMarkActiveIsNoopWhenTargetAlreadyActive(): void
    {
        $agent = $this->makeAgent('support');

        $target = new SynapseAgentPromptVersion();
        $target->setAgent($agent);
        $target->setIsActive(true);
        $this->setId($target, 5);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findActiveForAgent')->willReturn($target);

        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $recorder->markActive($agent, $target);

        $this->assertTrue($target->isActive());
    }

    public function testSnapshotPendingModeDoesNotDeactivatePreviousActive(): void
    {
        $agent = $this->makeAgent('support');

        $previous = new SynapseAgentPromptVersion();
        $previous->setAgent($agent);
        $previous->setSystemPrompt('Old prompt');
        $previous->setIsActive(true);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findLatestForAgent')->willReturn($previous);
        $repository->method('findActiveForAgent')->willReturn($previous);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (SynapseAgentPromptVersion $v): bool {
                return 'New prompt' === $v->getSystemPrompt()
                    && false === $v->isActive()
                    && PromptVersionStatus::Pending === $v->getStatus()
                    && true === $v->isPending();
            }));

        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());
        $result = $recorder->snapshot($agent, 'New prompt', 'mcp:claude', null, false, true);

        $this->assertNotNull($result);
        $this->assertTrue($previous->isActive(), 'live version must stay active during pending mode');
    }

    public function testApproveTransitionsPendingToActiveAndDeactivatesPrevious(): void
    {
        $agent = $this->makeAgent('support');

        $liveVersion = new SynapseAgentPromptVersion();
        $liveVersion->setAgent($agent);
        $liveVersion->setSystemPrompt('Live prompt');
        $liveVersion->setIsActive(true);
        $this->setId($liveVersion, 1);

        $pendingVersion = new SynapseAgentPromptVersion();
        $pendingVersion->setAgent($agent);
        $pendingVersion->setSystemPrompt('Proposed prompt');
        $pendingVersion->setStatus(PromptVersionStatus::Pending);
        $pendingVersion->setIsActive(false);
        $this->setId($pendingVersion, 2);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $repository->method('findActiveForAgent')->willReturn($liveVersion);

        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $recorder->approve($pendingVersion, 'human:alice');

        $this->assertSame(PromptVersionStatus::Approved, $pendingVersion->getStatus());
        $this->assertTrue($pendingVersion->isActive());
        $this->assertSame('human:alice', $pendingVersion->getReviewedBy());
        $this->assertNotNull($pendingVersion->getReviewedAt());
        $this->assertFalse($liveVersion->isActive(), 'previous active version must be deactivated on approval');
    }

    public function testRejectMarksVersionRejectedAndPreservesReason(): void
    {
        $agent = $this->makeAgent('support');

        $pendingVersion = new SynapseAgentPromptVersion();
        $pendingVersion->setAgent($agent);
        $pendingVersion->setSystemPrompt('Bad prompt');
        $pendingVersion->setStatus(PromptVersionStatus::Pending);
        $pendingVersion->setReason('Auto-suggested by coach');

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $recorder->reject($pendingVersion, 'human:bob', 'prompt introduces ambiguity');

        $this->assertSame(PromptVersionStatus::Rejected, $pendingVersion->getStatus());
        $this->assertFalse($pendingVersion->isActive());
        $this->assertSame('human:bob', $pendingVersion->getReviewedBy());
        $this->assertStringContainsString('Auto-suggested by coach', (string) $pendingVersion->getReason());
        $this->assertStringContainsString('Rejected: prompt introduces ambiguity', (string) $pendingVersion->getReason());
    }

    public function testApproveRejectsNonPendingVersion(): void
    {
        $agent = $this->makeAgent('support');

        $version = new SynapseAgentPromptVersion();
        $version->setAgent($agent);
        $version->setStatus(null); // live mode, pas pending

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $this->expectException(\LogicException::class);
        $recorder->approve($version, 'human:alice');
    }

    public function testRejectRejectsNonPendingVersion(): void
    {
        $agent = $this->makeAgent('support');

        $version = new SynapseAgentPromptVersion();
        $version->setAgent($agent);
        $version->setStatus(PromptVersionStatus::Approved);

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $this->expectException(\LogicException::class);
        $recorder->reject($version, 'human:bob');
    }

    public function testApproveRejectsOrphanVersion(): void
    {
        $version = new SynapseAgentPromptVersion();
        $version->setStatus(PromptVersionStatus::Pending);
        $version->setAgentKey('deleted_agent');
        // agent left null (FK SET NULL after deletion)

        $repository = $this->createStub(SynapseAgentPromptVersionRepository::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $recorder = new PromptVersionRecorder($em, $repository, new NullPromptJudge());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/orphan/i');
        $recorder->approve($version, 'human:alice');
    }

    private function makeAgent(string $key): SynapseAgent
    {
        $agent = new SynapseAgent();
        $agent->setKey($key);
        $agent->setName($key);

        return $agent;
    }

    private function setId(SynapseAgentPromptVersion $version, int $id): void
    {
        $ref = new \ReflectionProperty(SynapseAgentPromptVersion::class, 'id');
        $ref->setValue($version, $id);
    }
}
