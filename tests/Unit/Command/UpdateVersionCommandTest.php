<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\UpdateVersionCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateVersionCommandTest extends TestCase
{
    private string $versionFile;
    private string $originalDir;

    protected function setUp(): void
    {
        // The command computes: dirname(__DIR__, 3) from its location in src/Command/,
        // which resolves to packages/ — so the VERSION file is written to packages/VERSION
        // The command uses dirname(__DIR__, 3) where __DIR__ = .../packages/core/src/Command
        // dirname(dir, 3) = .../packages — so VERSION ends up in packages/VERSION
        $commandFile = (new \ReflectionClass(UpdateVersionCommand::class))->getFileName();
        $commandDir = dirname((string) $commandFile);
        $this->versionFile = dirname($commandDir, 3).'/VERSION';
        // Back up existing file if present
        if (file_exists($this->versionFile)) {
            $this->originalDir = (string) file_get_contents($this->versionFile);
        } else {
            $this->originalDir = '';
        }
    }

    protected function tearDown(): void
    {
        // Restore original VERSION file
        if ('' !== $this->originalDir) {
            file_put_contents($this->versionFile, $this->originalDir);
        } elseif (file_exists($this->versionFile)) {
            unlink($this->versionFile);
        }
    }

    public function testVersionFileIsWritten(): void
    {
        $command = new UpdateVersionCommand();
        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Version updated to', $tester->getDisplay());

        $this->assertFileExists($this->versionFile);
        $content = trim((string) file_get_contents($this->versionFile));
        $this->assertStringStartsWith('dev 0.', $content);
    }

    public function testVersionContainsCurrentDate(): void
    {
        $command = new UpdateVersionCommand();
        $tester = new CommandTester($command);
        $tester->execute([]);

        $expectedDate = (new \DateTime())->format('ymd');
        $content = trim((string) file_get_contents($this->versionFile));
        $this->assertSame("dev 0.{$expectedDate}", $content);
    }
}
