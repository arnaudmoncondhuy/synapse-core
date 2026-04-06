<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Doctor;

use ArnaudMoncondhuy\SynapseCore\Doctor\DoctrineMappingValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class DoctrineMappingValidatorTest extends TestCase
{
    public function testValidateSkipsWhenDoctrineConfigMissing(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')
            ->with('/project/config/packages/doctrine.yaml')
            ->willReturn(false);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('writeln')
            ->with($this->stringContains('[SKIP]'));

        $validator = new DoctrineMappingValidator($filesystem);

        $this->assertTrue($validator->validate('/project', false, $io));
    }

    public function testValidateReturnsFalseWhenNoMappingsConfigured(): void
    {
        $tmpDir = sys_get_temp_dir().'/synapse_doctrine_test_'.uniqid();
        $configDir = $tmpDir.'/config/packages';
        mkdir($configDir, 0777, true);

        $yamlContent = "doctrine:\n  orm:\n    auto_generate_proxy_classes: true\n";
        file_put_contents($configDir.'/doctrine.yaml', $yamlContent);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('error')
            ->with($this->stringContains('No ORM mappings'));

        $validator = new DoctrineMappingValidator($filesystem);

        $result = $validator->validate($tmpDir, false, $io);

        unlink($configDir.'/doctrine.yaml');
        rmdir($configDir);
        rmdir($tmpDir.'/config');
        rmdir($tmpDir);

        $this->assertFalse($result);
    }

    public function testValidateReturnsTrueWhenEntityDirIsMapped(): void
    {
        $tmpDir = sys_get_temp_dir().'/synapse_doctrine_test_'.uniqid();
        $configDir = $tmpDir.'/config/packages';
        mkdir($configDir, 0777, true);

        $yamlContent = <<<'YAML'
doctrine:
  orm:
    mappings:
      App:
        dir: '%kernel.project_dir%/src/Entity'
        prefix: 'App\Entity'
        type: attribute
YAML;
        file_put_contents($configDir.'/doctrine.yaml', $yamlContent);

        // No src/Entity dir means the check for entity mapping is skipped
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturnCallback(function (string $path) use ($tmpDir): bool {
            if ($path === $tmpDir.'/config/packages/doctrine.yaml') {
                return true;
            }
            if ($path === $tmpDir.'/src/Entity') {
                return false;
            }

            return false;
        });

        $io = $this->createStub(SymfonyStyle::class);
        $validator = new DoctrineMappingValidator($filesystem);

        $result = $validator->validate($tmpDir, false, $io);

        unlink($configDir.'/doctrine.yaml');
        rmdir($configDir);
        rmdir($tmpDir.'/config');
        rmdir($tmpDir);

        $this->assertTrue($result);
    }
}
