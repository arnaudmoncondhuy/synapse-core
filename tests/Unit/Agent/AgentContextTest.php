<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext
 */
final class AgentContextTest extends TestCase
{
    public function testRootCreatesContextWithDepthZero(): void
    {
        $ctx = AgentContext::root(userId: 'user-42', origin: 'direct');

        $this->assertSame(0, $ctx->getDepth());
        $this->assertFalse($ctx->hasParent());
        $this->assertNull($ctx->getParentRunId());
        $this->assertSame('user-42', $ctx->getUserId());
        $this->assertSame('direct', $ctx->getOrigin());
        $this->assertNotEmpty($ctx->getRequestId());
        $this->assertSame(AgentContext::DEFAULT_MAX_DEPTH, $ctx->getMaxDepth());
    }

    public function testCreateChildIncrementsDepthAndPreservesUser(): void
    {
        $root = AgentContext::root(userId: 'user-42', maxDepth: 3);
        $child = $root->createChild(parentRunId: $root->getRequestId(), childOrigin: 'code');

        $this->assertSame(1, $child->getDepth());
        $this->assertSame(3, $child->getMaxDepth());
        $this->assertSame('user-42', $child->getUserId());
        $this->assertTrue($child->hasParent());
        $this->assertSame($root->getRequestId(), $child->getParentRunId());
        $this->assertSame('code', $child->getOrigin());
        $this->assertNotSame($root->getRequestId(), $child->getRequestId(), 'child must have its own requestId');
    }

    public function testIsDepthExceededTriggersAtMaxDepth(): void
    {
        $ctx = AgentContext::root(maxDepth: 2);
        $this->assertFalse($ctx->isDepthExceeded());

        $child1 = $ctx->createChild('run-1');
        $this->assertFalse($child1->isDepthExceeded());

        $child2 = $child1->createChild('run-2');
        $this->assertTrue($child2->isDepthExceeded(), 'depth 2 with maxDepth 2 must be exceeded');
    }

    public function testContextIsSerializable(): void
    {
        // Critical for Messenger: the context must survive serialize/unserialize
        // because it will be transported via async message queues.
        $ctx = AgentContext::root(userId: 'user-42', origin: 'workflow');
        $child = $ctx->createChild(parentRunId: $ctx->getRequestId());

        $serialized = serialize($child);
        /** @var AgentContext $restored */
        $restored = unserialize($serialized);

        $this->assertInstanceOf(AgentContext::class, $restored);
        $this->assertSame($child->getRequestId(), $restored->getRequestId());
        $this->assertSame($child->getParentRunId(), $restored->getParentRunId());
        $this->assertSame($child->getDepth(), $restored->getDepth());
        $this->assertSame($child->getUserId(), $restored->getUserId());
        $this->assertSame($child->getOrigin(), $restored->getOrigin());
    }

    public function testWithWorkflowRunIdReturnsNewInstance(): void
    {
        $root = AgentContext::root(userId: 'user-42', origin: 'workflow');
        $this->assertNull($root->getWorkflowRunId());

        $enriched = $root->withWorkflowRunId('wf-run-abc-123');

        // Nouvelle instance, tous les autres champs préservés
        $this->assertNotSame($root, $enriched);
        $this->assertSame('wf-run-abc-123', $enriched->getWorkflowRunId());
        $this->assertSame($root->getRequestId(), $enriched->getRequestId());
        $this->assertSame($root->getUserId(), $enriched->getUserId());
        $this->assertSame($root->getDepth(), $enriched->getDepth());
        $this->assertSame($root->getOrigin(), $enriched->getOrigin());

        // Immutabilité : l'original reste inchangé
        $this->assertNull($root->getWorkflowRunId());
    }

    public function testWithWorkflowRunIdPropagatesThroughCreateChild(): void
    {
        $root = AgentContext::root(userId: 'user-42')
            ->withWorkflowRunId('wf-run-abc-123');
        $child = $root->createChild(parentRunId: $root->getRequestId());

        // Le workflowRunId doit descendre dans les enfants via createChild
        $this->assertSame('wf-run-abc-123', $child->getWorkflowRunId());
    }

    public function testContextWithWorkflowRunIdIsSerializable(): void
    {
        // Les runs workflow sont transportés via Messenger (Phase 9) —
        // vérifier que workflowRunId survit au round-trip serialize/unserialize.
        $ctx = AgentContext::root(userId: 'user-42', origin: 'workflow')
            ->withWorkflowRunId('wf-run-abc-123');

        $serialized = serialize($ctx);
        /** @var AgentContext $restored */
        $restored = unserialize($serialized);

        $this->assertSame('wf-run-abc-123', $restored->getWorkflowRunId());
    }
}
