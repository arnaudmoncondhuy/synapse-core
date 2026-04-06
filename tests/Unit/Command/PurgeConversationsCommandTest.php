<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\PurgeConversationsCommand;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class PurgeConversationsCommandTest extends TestCase
{
    private function buildCommand(SynapseConversationRepository $repo): PurgeConversationsCommand
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')
            ->willReturn($repo);

        return new PurgeConversationsCommand($em, 30);
    }

    public function testNoConversationsToPurge(): void
    {
        $repo = $this->createStub(SynapseConversationRepository::class);
        $repo->method('findOlderThan')->willReturn([]);

        $tester = new CommandTester($this->buildCommand($repo));
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Aucune conversation', $tester->getDisplay());
    }

    public function testDryRunDoesNotDelete(): void
    {
        $conversation = $this->createStub(SynapseConversation::class);
        $conversation->method('getOwner')->willReturn(null);
        $conversation->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-60 days'));

        $repo = $this->createMock(SynapseConversationRepository::class);
        $repo->method('findOlderThan')->willReturn([$conversation]);
        $repo->expects($this->never())->method('hardDelete');

        $tester = new CommandTester($this->buildCommand($repo));
        $tester->execute(['--dry-run' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('SIMULATION', $tester->getDisplay());
        $this->assertStringContainsString('aucune suppression', $tester->getDisplay());
    }

    public function testInvalidDaysReturnFailure(): void
    {
        $repo = $this->createStub(SynapseConversationRepository::class);

        $tester = new CommandTester($this->buildCommand($repo));
        $tester->execute(['--days' => '0']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('doit', $tester->getDisplay());
    }

    public function testRealDeleteWithConfirmation(): void
    {
        $conversation = $this->createStub(SynapseConversation::class);
        $conversation->method('getOwner')->willReturn(null);
        $conversation->method('getUpdatedAt')->willReturn(new \DateTimeImmutable('-60 days'));
        $conversation->method('getId')->willReturn('abc-123');

        $repo = $this->createMock(SynapseConversationRepository::class);
        $repo->method('findOlderThan')->willReturn([$conversation]);
        $repo->expects($this->once())->method('hardDelete')->with([$conversation]);

        $tester = new CommandTester($this->buildCommand($repo));
        $tester->setInputs(['yes']);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('1/1 conversation(s)', $tester->getDisplay());
    }
}
