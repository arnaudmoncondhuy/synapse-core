<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Logger;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog;
use ArnaudMoncondhuy\SynapseCore\Storage\Logger\DoctrineAdminLogger;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class DoctrineAdminLoggerTest extends TestCase
{
    private EntityManagerInterface $em;
    private SynapseDebugLogRepository $repository;
    private DoctrineAdminLogger $logger;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->repository = $this->createMock(SynapseDebugLogRepository::class);
        $this->logger = new DoctrineAdminLogger($this->em, $this->repository);
    }

    public function testLogExchangePersistsAndFlushes(): void
    {
        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (SynapseDebugLog $log): bool {
                return 'dbg_123' === $log->getDebugId()
                    && 'gemini' === $log->getModule()
                    && 'gpt-4' === $log->getModel()
                    && 150 === $log->getTotalTokens();
            }));
        $this->em->expects($this->once())->method('flush');

        $this->logger->logExchange(
            'dbg_123',
            ['conversation_id' => 'conv_abc'],
            [
                'module' => 'gemini',
                'model' => 'gpt-4',
                'usage' => ['total_tokens' => 150],
            ]
        );
    }

    public function testLogExchangeComputesTotalFromPromptAndCompletion(): void
    {
        $this->em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (SynapseDebugLog $log): bool {
                return 300 === $log->getTotalTokens();
            }));
        $this->em->expects($this->once())->method('flush');

        $this->logger->logExchange(
            'dbg_456',
            [],
            [
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
            ]
        );
    }

    public function testFindByDebugIdReturnsNullWhenNotFound(): void
    {
        $this->repository->method('findByDebugId')->willReturn(null);

        $this->assertNull($this->logger->findByDebugId('nonexistent'));
    }

    public function testFindByDebugIdReturnsDataWhenFound(): void
    {
        $debugLog = new SynapseDebugLog();
        $debugLog->setDebugId('dbg_found');
        $debugLog->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
        $debugLog->setData(['some' => 'data']);

        $this->repository->method('findByDebugId')->with('dbg_found')->willReturn($debugLog);

        $result = $this->logger->findByDebugId('dbg_found');

        $this->assertNotNull($result);
        $this->assertSame('dbg_found', $result['debug_id']);
        $this->assertSame(['some' => 'data'], $result['data']);
    }
}
