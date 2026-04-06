<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Doctor;

use ArnaudMoncondhuy\SynapseCore\Doctor\RepositoryValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class RepositoryValidatorTest extends TestCase
{
    public function testValidateReturnsTrueWhenEntityDirMissing(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        $io = $this->createStub(SymfonyStyle::class);
        $validator = new RepositoryValidator($filesystem);

        $this->assertTrue($validator->validate('/project', false, $io));
    }

    public function testValidateReturnsTrueWhenNoRepositoryClassAttribute(): void
    {
        $tmpDir = sys_get_temp_dir().'/synapse_repo_test_'.uniqid();
        $entityDir = $tmpDir.'/src/Entity';
        mkdir($entityDir, 0777, true);

        file_put_contents($entityDir.'/SimpleEntity.php', <<<'PHP'
<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class SimpleEntity
{
    #[ORM\Id]
    private int $id;
}
PHP);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createStub(SymfonyStyle::class);
        $validator = new RepositoryValidator($filesystem);

        $result = $validator->validate($tmpDir, false, $io);

        unlink($entityDir.'/SimpleEntity.php');
        rmdir($entityDir);
        rmdir($tmpDir.'/src');
        rmdir($tmpDir);

        $this->assertTrue($result);
    }

    public function testValidateReturnsFalseForMissingRepositoryClass(): void
    {
        $tmpDir = sys_get_temp_dir().'/synapse_repo_test_'.uniqid();
        $entityDir = $tmpDir.'/src/Entity';
        mkdir($entityDir, 0777, true);

        file_put_contents($entityDir.'/TestEntity.php', <<<'PHP'
<?php
namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NonExistentRepository::class)]
class TestEntity
{
    #[ORM\Id]
    private int $id;
}
PHP);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('error')
            ->with($this->stringContains('not found'));

        $validator = new RepositoryValidator($filesystem);

        $result = $validator->validate($tmpDir, false, $io);

        unlink($entityDir.'/TestEntity.php');
        rmdir($entityDir);
        rmdir($tmpDir.'/src');
        rmdir($tmpDir);

        $this->assertFalse($result);
    }
}
