<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit;

use ArnaudMoncondhuy\SynapseCore\Core\ToneRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseToneRepository;
use PHPUnit\Framework\TestCase;

class ToneRegistryTest extends TestCase
{
    private $repository;
    private $registry;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SynapseToneRepository::class);
        $this->registry = new ToneRegistry($this->repository);
    }

    public function testGetSystemPromptReturnsPromptWhenActive(): void
    {
        $tone = $this->createMock(SynapseTone::class);
        $tone->method('isActive')->willReturn(true);
        $tone->method('getSystemPrompt')->willReturn('Be zen.');

        $this->repository->method('findByKey')->with('zen')->willReturn($tone);

        $this->assertSame('Be zen.', $this->registry->getSystemPrompt('zen'));
    }

    public function testGetSystemPromptReturnsNullWhenInactive(): void
    {
        $tone = $this->createMock(SynapseTone::class);
        $tone->method('isActive')->willReturn(false);

        $this->repository->method('findByKey')->with('lazy')->willReturn($tone);

        $this->assertNull($this->registry->getSystemPrompt('lazy'));
    }

    public function testGetAllReturnsIndexedArray(): void
    {
        $tone1 = $this->createMock(SynapseTone::class);
        $tone1->method('getKey')->willReturn('t1');
        $tone1->method('toArray')->willReturn(['name' => 'Tone 1']);

        $this->repository->method('findAllActive')->willReturn([$tone1]);

        $all = $this->registry->getAll();
        $this->assertArrayHasKey('t1', $all);
        $this->assertSame('Tone 1', $all['t1']['name']);
    }
}
