<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\MessageHandler;

use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Message\ReindexRagSourceMessage;
use ArnaudMoncondhuy\SynapseCore\MessageHandler\ReindexRagSourceHandler;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReindexRagSourceHandlerTest extends TestCase
{
    private SynapseRagSourceRepository $sourceRepository;
    private RagManager $ragManager;
    private RagSourceRegistry $registry;
    private EntityManagerInterface $em;
    private ReindexRagSourceHandler $handler;

    protected function setUp(): void
    {
        $this->sourceRepository = $this->createMock(SynapseRagSourceRepository::class);
        $this->ragManager = $this->createMock(RagManager::class);
        $this->registry = $this->createMock(RagSourceRegistry::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->handler = new ReindexRagSourceHandler(
            $this->sourceRepository,
            $this->ragManager,
            $this->registry,
            $this->em,
            new NullLogger(),
        );
    }

    public function testInvokeWithNonExistentSourceDoesNothing(): void
    {
        $this->sourceRepository->method('find')->with(999)->willReturn(null);
        $this->em->expects($this->never())->method('flush');

        ($this->handler)(new ReindexRagSourceMessage(999));
    }

    public function testInvokeWithNoProviderSetsErrorStatus(): void
    {
        $source = new SynapseRagSource();
        $source->setSlug('missing_provider');

        $this->sourceRepository->method('find')->with(1)->willReturn($source);
        $this->registry->method('get')->with('missing_provider')->willReturn(null);
        $this->em->expects($this->once())->method('flush');

        ($this->handler)(new ReindexRagSourceMessage(1));

        $this->assertSame('error', $source->getIndexingStatus());
        $this->assertStringContainsString('missing_provider', $source->getLastError());
    }

    public function testInvokeWithProviderCompletesIndexing(): void
    {
        $source = new SynapseRagSource();
        $source->setSlug('my_source');

        $provider = $this->createMock(RagSourceProviderInterface::class);
        $provider->method('fetchDocuments')->willReturn(new \ArrayIterator([
            ['title' => 'Doc1', 'content' => 'Content 1'],
        ]));

        $this->sourceRepository->method('find')->with(1)->willReturn($source);
        $this->registry->method('get')->with('my_source')->willReturn($provider);
        $this->ragManager->expects($this->once())->method('clear')->with('my_source');
        $this->ragManager->method('ingest')->willReturn(3);

        ($this->handler)(new ReindexRagSourceMessage(1));

        $this->assertSame('ready', $source->getIndexingStatus());
        $this->assertSame(3, $source->getDocumentCount());
        $this->assertSame(1, $source->getProcessedFiles());
        $this->assertNotNull($source->getLastIndexedAt());
    }
}
