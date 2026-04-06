<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Accounting;

use ArnaudMoncondhuy\SynapseCore\Accounting\EmbeddingUsageListener;
use ArnaudMoncondhuy\SynapseCore\Accounting\TokenAccountingService;
use ArnaudMoncondhuy\SynapseCore\Shared\Event\SynapseEmbeddingCompletedEvent;
use PHPUnit\Framework\TestCase;

class EmbeddingUsageListenerTest extends TestCase
{
    public function testInvokeLogsRagIndexation(): void
    {
        $accounting = $this->createMock(TokenAccountingService::class);
        $accounting->expects($this->once())
            ->method('logUsage')
            ->with(
                module: 'rag',
                action: 'indexation',
                model: 'text-embedding-004',
                usage: $this->anything(),
            );

        $listener = new EmbeddingUsageListener($accounting);
        $event = new SynapseEmbeddingCompletedEvent('text-embedding-004', 'google', 100, 100, 'rag_indexation');

        $listener($event);
    }

    public function testInvokeLogsRagSearch(): void
    {
        $accounting = $this->createMock(TokenAccountingService::class);
        $accounting->expects($this->once())
            ->method('logUsage')
            ->with(
                module: 'conversation',
                action: 'rag_search',
                model: 'model',
                usage: $this->anything(),
            );

        $listener = new EmbeddingUsageListener($accounting);
        $event = new SynapseEmbeddingCompletedEvent('model', 'provider', 50, 50, 'rag_search');

        $listener($event);
    }

    public function testInvokeLogsMemorySearch(): void
    {
        $accounting = $this->createMock(TokenAccountingService::class);
        $accounting->expects($this->once())
            ->method('logUsage')
            ->with(
                module: 'conversation',
                action: 'memory_search',
                model: 'model',
                usage: $this->anything(),
            );

        $listener = new EmbeddingUsageListener($accounting);
        $event = new SynapseEmbeddingCompletedEvent('model', 'p', 10, 10, 'memory_search');

        $listener($event);
    }

    public function testInvokeWithUnknownPurposeDefaultsToRag(): void
    {
        $accounting = $this->createMock(TokenAccountingService::class);
        $accounting->expects($this->once())
            ->method('logUsage')
            ->with(
                module: 'rag',
                action: 'indexation',
                model: 'model',
                usage: $this->anything(),
            );

        $listener = new EmbeddingUsageListener($accounting);
        $event = new SynapseEmbeddingCompletedEvent('model', 'p', 5, 5, 'unknown_purpose');

        $listener($event);
    }
}
