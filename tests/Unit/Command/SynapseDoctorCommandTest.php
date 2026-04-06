<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Command;

use ArnaudMoncondhuy\SynapseCore\Command\SynapseDoctorCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

class SynapseDoctorCommandTest extends TestCase
{
    private Filesystem $filesystem;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/synapse_doctor_test_'.uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->filesystem = new Filesystem();
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->tmpDir);
    }

    private function buildCommand(): SynapseDoctorCommand
    {
        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->tmpDir);
        $kernel->method('getBundles')->willReturn([]);

        $parameterBag = $this->createStub(ParameterBagInterface::class);
        $parameterBag->method('has')->willReturn(false);
        $parameterBag->method('get')->willReturn('');

        return new SynapseDoctorCommand($kernel, $parameterBag, $this->filesystem);
    }

    public function testDoctorOutputsTitleAndSummary(): void
    {
        $tester = new CommandTester($this->buildCommand());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Synapse Doctor', $display);
        // Some checks will fail in an empty project dir, so the command returns FAILURE
        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('issue(s) detected', $display);
    }

    public function testPhpVersionCheckPasses(): void
    {
        $tester = new CommandTester($this->buildCommand());
        $tester->execute([]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('[OK] PHP', $display);
    }

    public function testFixOptionIsAccepted(): void
    {
        $tester = new CommandTester($this->buildCommand());
        $tester->execute(['--fix' => true]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Synapse Doctor', $display);
    }
}
