<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Service;

use ArnaudMoncondhuy\SynapseCore\Service\ToneInitializer;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use Doctrine\Persistence\ObjectManager;
use PHPUnit\Framework\TestCase;

class ToneInitializerTest extends TestCase
{
    public function testInitializeCreatesNewTones(): void
    {
        $toneRepo = $this->createStub(SynapseToneRepository::class);
        $toneRepo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->atLeastOnce())->method('persist');
        $em->expects($this->once())->method('flush');

        $initializer = new ToneInitializer($toneRepo);
        $count = $initializer->initialize($em);

        $this->assertSame(20, $count);
    }

    public function testInitializeSkipsExistingTones(): void
    {
        $existing = $this->createStub(SynapseTone::class);

        $toneRepo = $this->createStub(SynapseToneRepository::class);
        $toneRepo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(ObjectManager::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $initializer = new ToneInitializer($toneRepo);
        $count = $initializer->initialize($em);

        $this->assertSame(0, $count);
    }

    public function testGetDefaultTonesDataReturns20Items(): void
    {
        $toneRepo = $this->createStub(SynapseToneRepository::class);
        $initializer = new ToneInitializer($toneRepo);

        $data = $initializer->getDefaultTonesData();

        $this->assertCount(20, $data);
        $this->assertArrayHasKey('key', $data[0]);
        $this->assertArrayHasKey('emoji', $data[0]);
        $this->assertArrayHasKey('name', $data[0]);
    }
}
