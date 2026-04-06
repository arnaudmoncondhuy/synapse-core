<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Doctor;

use ArnaudMoncondhuy\SynapseCore\Doctor\AssetMapperValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;

class AssetMapperValidatorTest extends TestCase
{
    public function testValidateSkipsWhenAssetsDirMissing(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')
            ->with('/project/assets')
            ->willReturn(false);

        $kernel = $this->createStub(KernelInterface::class);
        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('writeln')
            ->with($this->stringContains('[SKIP]'));

        $validator = new AssetMapperValidator($filesystem, $kernel);

        $this->assertTrue($validator->validate('/project', false, $io));
    }

    public function testValidateReturnsOkWhenAllSymlinksExist(): void
    {
        $bundle = $this->createStub(BundleInterface::class);
        $bundle->method('getPath')->willReturn('/vendor/synapse-admin');

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getBundles')->willReturn([
            'SynapseAdminBundle' => $bundle,
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturnCallback(function (string $path): bool {
            return match ($path) {
                '/project/assets' => true,
                '/vendor/synapse-admin/assets' => true,
                '/project/assets/synapse-admin' => true,
                default => false,
            };
        });

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('writeln')
            ->with($this->stringContains('[OK]'));

        $validator = new AssetMapperValidator($filesystem, $kernel);

        $this->assertTrue($validator->validate('/project', false, $io));
    }

    public function testValidateReturnsFalseWhenSymlinkMissing(): void
    {
        $bundle = $this->createStub(BundleInterface::class);
        $bundle->method('getPath')->willReturn('/vendor/synapse-chat');

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getBundles')->willReturn([
            'SynapseChatBundle' => $bundle,
        ]);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturnCallback(function (string $path): bool {
            return match ($path) {
                '/project/assets' => true,
                '/vendor/synapse-chat/assets' => true,
                '/project/assets/synapse-chat' => false,
                default => false,
            };
        });

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('error')
            ->with($this->stringContains('Missing symlink'));

        $validator = new AssetMapperValidator($filesystem, $kernel);

        $this->assertFalse($validator->validate('/project', false, $io));
    }
}
