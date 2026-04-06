<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Doctor;

use ArnaudMoncondhuy\SynapseCore\Doctor\TypedPropertyValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class TypedPropertyValidatorTest extends TestCase
{
    public function testValidateReturnsTrueWhenEntityDirMissing(): void
    {
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(false);

        $io = $this->createStub(SymfonyStyle::class);
        $validator = new TypedPropertyValidator($filesystem);

        $this->assertTrue($validator->validate('/project', false, $io));
    }

    public function testValidateReturnsTrueWhenCollectionIsInitialized(): void
    {
        $tmpDir = sys_get_temp_dir().'/synapse_typed_test_'.uniqid();
        $entityDir = $tmpDir.'/src/Entity';
        mkdir($entityDir, 0777, true);

        file_put_contents($entityDir.'/GoodEntity.php', <<<'PHP'
<?php
namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class GoodEntity
{
    #[ORM\OneToMany(targetEntity: Child::class, mappedBy: 'parent')]
    private Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}
PHP);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('writeln')
            ->with($this->stringContains('[OK]'));

        $validator = new TypedPropertyValidator($filesystem);

        $result = $validator->validate($tmpDir, false, $io);

        unlink($entityDir.'/GoodEntity.php');
        rmdir($entityDir);
        rmdir($tmpDir.'/src');
        rmdir($tmpDir);

        $this->assertTrue($result);
    }

    public function testValidateWarnsWhenCollectionNotInitialized(): void
    {
        $tmpDir = sys_get_temp_dir().'/synapse_typed_test_'.uniqid();
        $entityDir = $tmpDir.'/src/Entity';
        mkdir($entityDir, 0777, true);

        file_put_contents($entityDir.'/BadEntity.php', <<<'PHP'
<?php
namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class BadEntity
{
    #[ORM\OneToMany(targetEntity: Child::class, mappedBy: 'parent')]
    private Collection $children;
}
PHP);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('exists')->willReturn(true);

        $io = $this->createMock(SymfonyStyle::class);
        $io->expects($this->once())->method('writeln')
            ->with($this->stringContains('[WARN]'));

        $validator = new TypedPropertyValidator($filesystem);

        $result = $validator->validate($tmpDir, false, $io);

        unlink($entityDir.'/BadEntity.php');
        rmdir($entityDir);
        rmdir($tmpDir.'/src');
        rmdir($tmpDir);

        $this->assertFalse($result);
    }
}
