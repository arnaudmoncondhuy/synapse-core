<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Integration\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseLlmCallRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

class SynapseLlmCallRepositoryTest extends TestCase
{
    private function createRepository(
        array $mockResultFetch = [],
        array $mockResultAll = [],
    ): SynapseLlmCallRepository {
        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAssociative')->willReturn($mockResultFetch ?: ($mockResultAll[0] ?? false));
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResultAll);

        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->method('executeQuery')->willReturn($mockStatement);

        $mockMetadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $mockMetadata->name = \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall::class;

        $mockEm = $this->createMock(EntityManagerInterface::class);
        $mockEm->method('getConnection')->willReturn($mockConnection);
        $mockEm->method('getClassMetadata')->willReturn($mockMetadata);

        $mockRegistry = $this->createMock(ManagerRegistry::class);
        $mockRegistry->method('getManagerForClass')->willReturn($mockEm);

        return new SynapseLlmCallRepository($mockRegistry);
    }

    public function testGetGlobalStatsReturnsCostsGroupedByCurrency(): void
    {
        $mockResult = [
            [
                'request_count' => 10,
                'prompt_tokens' => 1000,
                'completion_tokens' => 500,
                'thinking_tokens' => 100,
                'total_tokens' => 1600,
                'currency' => 'EUR',
                'cost' => 0.005,
            ],
            [
                'request_count' => 5,
                'prompt_tokens' => 800,
                'completion_tokens' => 300,
                'thinking_tokens' => 0,
                'total_tokens' => 1100,
                'currency' => 'USD',
                'cost' => 0.00015,
            ],
        ];

        $repository = $this->createRepository([], $mockResult);

        $stats = $repository->getGlobalStats(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertArrayHasKey('costs', $stats);
        $this->assertArrayHasKey('EUR', $stats['costs']);
        $this->assertArrayHasKey('USD', $stats['costs']);
        $this->assertEquals(0.005, $stats['costs']['EUR']);
        $this->assertEquals(0.00015, $stats['costs']['USD']);
    }

    public function testGetGlobalStatsReturnsZeroWhenNoData(): void
    {
        $repository = $this->createRepository([], []);

        $stats = $repository->getGlobalStats(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertArrayHasKey('costs', $stats);
        $this->assertEmpty($stats['costs']);
        $this->assertSame(0, $stats['total_tokens'] ?? 0);
    }

    public function testGetUsageByModelReturnsCurrencyPerModel(): void
    {
        $mockResult = [
            [
                'model' => 'gemini-2.0-flash',
                'count' => 5,
                'total_tokens' => 1000,
                'cost' => 0.00005,
                'pricing_currency' => 'USD',
            ],
            [
                'model' => 'mistral-large',
                'count' => 3,
                'total_tokens' => 500,
                'cost' => 0.00002,
                'pricing_currency' => 'EUR',
            ],
        ];

        $repository = $this->createRepository([], $mockResult);

        $usage = $repository->getUsageByModel(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertCount(2, $usage);
        $this->assertArrayHasKey('gemini-2.0-flash', $usage);
        $this->assertSame('USD', $usage['gemini-2.0-flash']['currency']);
        $this->assertArrayHasKey('mistral-large', $usage);
        $this->assertSame('EUR', $usage['mistral-large']['currency']);
    }

    public function testGetDailyUsageReturnsIndexedByDate(): void
    {
        $mockResult = [
            [
                'date' => '2026-03-01',
                'total_tokens' => 1000,
                'prompt_tokens' => 600,
                'completion_tokens' => 300,
                'thinking_tokens' => 100,
            ],
            [
                'date' => '2026-03-02',
                'total_tokens' => 1500,
                'prompt_tokens' => 900,
                'completion_tokens' => 500,
                'thinking_tokens' => 100,
            ],
        ];

        $repository = $this->createRepository([], $mockResult);

        $daily = $repository->getDailyUsage(
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable(),
        );

        $this->assertCount(2, $daily);
        $this->assertArrayHasKey('2026-03-01', $daily);
        $this->assertSame(1000, $daily['2026-03-01']['total_tokens']);
    }

    public function testGetDailyUsageReturnsEmptyArrayWhenNoData(): void
    {
        $repository = $this->createRepository([], []);

        $daily = $repository->getDailyUsage(
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable(),
        );

        $this->assertIsArray($daily);
        $this->assertEmpty($daily);
    }
}
