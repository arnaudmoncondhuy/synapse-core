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
    /**
     * Helper: Create a repository with mocked Connection and ManagerRegistry
     */
    private function createRepository(
        \Doctrine\DBAL\Result $mockStatement,
    ): SynapseLlmCallRepository {
        $mockConnection = $this->createMock(Connection::class);
        $mockConnection->expects($this->once())
            ->method('executeQuery')
            ->willReturn($mockStatement);

        $mockEm = $this->createMock(EntityManagerInterface::class);
        $mockEm->method('getConnection')->willReturn($mockConnection);

        $mockRegistry = $this->createMock(ManagerRegistry::class);
        $mockRegistry->method('getManagerForClass')->willReturn($mockEm);

        // Create a mock ClassMetadata
        $mockMetadata = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);

        return new SynapseLlmCallRepository($mockRegistry, $mockMetadata);
    }

    public function testGetGlobalStats_returnsCostsGroupedByCurrency(): void
    {
        $mockResult = [
            [
                'request_count' => 10,
                'prompt_tokens' => 1000,
                'completion_tokens' => 500,
                'thinking_tokens' => 100,
                'total_tokens' => 1600,
                'currency' => 'EUR',
                'total_cost' => 0.005,
            ],
            [
                'request_count' => 5,
                'prompt_tokens' => 800,
                'completion_tokens' => 300,
                'thinking_tokens' => 0,
                'total_tokens' => 1100,
                'currency' => 'USD',
                'total_cost' => 0.00015,
            ],
        ];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $stats = $repository->getGlobalStats(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertArrayHasKey('costs', $stats);
        $this->assertArrayHasKey('EUR', $stats['costs']);
        $this->assertArrayHasKey('USD', $stats['costs']);
        $this->assertGreaterThan(0, $stats['costs']['EUR']);
        $this->assertGreaterThan(0, $stats['costs']['USD']);
    }

    public function testGetGlobalStats_returnsZero_whenNoData(): void
    {
        $mockResult = [];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $stats = $repository->getGlobalStats(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertArrayHasKey('costs', $stats);
        $this->assertEmpty($stats['costs']);
        $this->assertSame(0, $stats['total_tokens'] ?? 0);
    }

    public function testGetGlobalStats_handlesEurOnly(): void
    {
        $mockResult = [
            [
                'request_count' => 15,
                'prompt_tokens' => 2000,
                'completion_tokens' => 800,
                'thinking_tokens' => 200,
                'total_tokens' => 3000,
                'currency' => 'EUR',
                'total_cost' => 0.01,
            ],
        ];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $stats = $repository->getGlobalStats(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertArrayHasKey('EUR', $stats['costs']);
        $this->assertGreaterThan(0, $stats['costs']['EUR']);
        $this->assertArrayNotHasKey('USD', $stats['costs']);
    }

    public function testGetUsageByModel_returnsCurrencyPerModel(): void
    {
        $mockResult = [
            [
                'model_id' => 'gemini-2.0-flash',
                'count' => 5,
                'total_tokens' => 1000,
                'cost' => 0.00005,
                'currency' => 'USD',
            ],
            [
                'model_id' => 'mistral-large',
                'count' => 3,
                'total_tokens' => 500,
                'cost' => 0.00002,
                'currency' => 'EUR',
            ],
        ];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $usage = $repository->getUsageByModel(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertCount(2, $usage);
        $this->assertSame('gemini-2.0-flash', $usage[0]['model_id']);
        $this->assertSame('USD', $usage[0]['currency']);
        $this->assertSame('mistral-large', $usage[1]['model_id']);
        $this->assertSame('EUR', $usage[1]['currency']);
    }

    public function testGetUsageByModel_returnsEmptyArray_whenNoData(): void
    {
        $mockResult = [];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $usage = $repository->getUsageByModel(
            new \DateTimeImmutable('-7 days'),
            new \DateTimeImmutable(),
        );

        $this->assertIsArray($usage);
        $this->assertEmpty($usage);
    }

    public function testGetDailyUsage_returnsIndexedByDate(): void
    {
        $mockResult = [
            [
                'date' => '2026-03-01',
                'total_tokens' => 1000,
                'request_count' => 5,
            ],
            [
                'date' => '2026-03-02',
                'total_tokens' => 1500,
                'request_count' => 7,
            ],
            [
                'date' => '2026-03-03',
                'total_tokens' => 800,
                'request_count' => 3,
            ],
        ];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $daily = $repository->getDailyUsage(
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable(),
        );

        $this->assertCount(3, $daily);
        $this->assertArrayHasKey('date', $daily[0]);
        $this->assertArrayHasKey('total_tokens', $daily[0]);
        $this->assertSame('2026-03-01', $daily[0]['date']);
        $this->assertSame(1000, $daily[0]['total_tokens']);
    }

    public function testGetDailyUsage_returnsEmptyArray_whenNoData(): void
    {
        $mockResult = [];

        $mockStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $mockStatement->method('fetchAllAssociative')->willReturn($mockResult);

        $repository = $this->createRepository($mockStatement);

        $daily = $repository->getDailyUsage(
            new \DateTimeImmutable('-30 days'),
            new \DateTimeImmutable(),
        );

        $this->assertIsArray($daily);
        $this->assertEmpty($daily);
    }
}
