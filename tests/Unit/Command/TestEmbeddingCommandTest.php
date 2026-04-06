<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\TestEmbeddingCommand;
use ArnaudMoncondhuy\SynapseCore\Service\EmbeddingService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class TestEmbeddingCommandTest extends TestCase
{
    public function testSuccessfulEmbeddingGeneration(): void
    {
        $embeddingService = $this->createStub(EmbeddingService::class);
        $embeddingService->method('generateEmbeddings')
            ->willReturn([
                'embeddings' => [[0.1, 0.2, 0.3, 0.4, 0.5, 0.6]],
                'usage' => ['prompt_tokens' => 2, 'total_tokens' => 2],
            ]);

        $command = new TestEmbeddingCommand($embeddingService);
        $tester = new CommandTester($command);
        $tester->execute(['text' => 'Hello world']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Embedding', $display);
        $this->assertStringContainsString('Dimension du vecteur: 6', $display);
        $this->assertStringContainsString('0.1000', $display);
    }

    public function testEmbeddingWithSpecificModel(): void
    {
        $embeddingService = $this->createStub(EmbeddingService::class);
        $embeddingService->method('generateEmbeddings')
            ->willReturn([
                'embeddings' => [[0.5, 0.6, 0.7]],
            ]);

        $command = new TestEmbeddingCommand($embeddingService);
        $tester = new CommandTester($command);
        $tester->execute(['text' => 'Test text', '--model' => 'text-embedding-3-small']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('text-embedding-3-small', $tester->getDisplay());
    }

    public function testEmptyEmbeddingResultReturnsFailure(): void
    {
        $embeddingService = $this->createStub(EmbeddingService::class);
        $embeddingService->method('generateEmbeddings')
            ->willReturn(['embeddings' => [[]]]);

        $command = new TestEmbeddingCommand($embeddingService);
        $tester = new CommandTester($command);
        $tester->execute(['text' => 'test']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Aucun vecteur', $tester->getDisplay());
    }

    public function testServiceExceptionReturnsFailure(): void
    {
        $embeddingService = $this->createStub(EmbeddingService::class);
        $embeddingService->method('generateEmbeddings')
            ->willThrowException(new \RuntimeException('Provider unavailable'));

        $command = new TestEmbeddingCommand($embeddingService);
        $tester = new CommandTester($command);
        $tester->execute(['text' => 'test']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Provider unavailable', $tester->getDisplay());
    }
}
