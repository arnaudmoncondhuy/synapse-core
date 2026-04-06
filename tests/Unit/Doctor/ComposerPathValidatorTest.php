<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Doctor;

use ArnaudMoncondhuy\SynapseCore\Doctor\ComposerPathValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class ComposerPathValidatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/synapse_test_'.uniqid();
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $composerFile = $this->tmpDir.'/composer.json';
        if (file_exists($composerFile)) {
            unlink($composerFile);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testValidateReturnsTrueWhenComposerFileMissing(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        $io = $this->createStub(SymfonyStyle::class);
        $validator = new ComposerPathValidator($filesystem);

        $this->assertTrue($validator->validate('/nonexistent', false, $io));
    }

    public function testValidateDetectsPathRepositories(): void
    {
        $composerContent = json_encode([
            'repositories' => [
                ['type' => 'path', 'url' => '../packages/*'],
            ],
        ]);
        file_put_contents($this->tmpDir.'/composer.json', $composerContent);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->exactly(2))->method('writeln');

        $validator = new ComposerPathValidator($filesystem);

        $this->assertTrue($validator->validate($this->tmpDir, false, $io));
    }

    public function testValidateReportsProductionModeWhenNoRepositories(): void
    {
        file_put_contents($this->tmpDir.'/composer.json', json_encode(['name' => 'test/app']));

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('writeln')
            ->with($this->stringContains('Packagist'));

        $validator = new ComposerPathValidator($filesystem);

        $this->assertTrue($validator->validate($this->tmpDir, false, $io));
    }
}
