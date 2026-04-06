<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use PHPUnit\Framework\TestCase;

class SynapseToneTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $tone = new SynapseTone();

        $this->assertNull($tone->getId());
        $this->assertSame('', $tone->getKey());
        $this->assertSame('', $tone->getEmoji());
        $this->assertSame('', $tone->getName());
        $this->assertSame('', $tone->getDescription());
        $this->assertSame('', $tone->getSystemPrompt());
        $this->assertTrue($tone->isBuiltin());
        $this->assertTrue($tone->isActive());
        $this->assertSame(0, $tone->getSortOrder());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tone->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $tone->getUpdatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $tone = new SynapseTone();

        $tone->setKey('zen')
            ->setEmoji('🧘')
            ->setName('Zen')
            ->setDescription('Calm and focused')
            ->setSystemPrompt('Respond in a calm, zen manner.')
            ->setIsBuiltin(false)
            ->setIsActive(false)
            ->setSortOrder(3);

        $this->assertSame('zen', $tone->getKey());
        $this->assertSame('🧘', $tone->getEmoji());
        $this->assertSame('Zen', $tone->getName());
        $this->assertSame('Calm and focused', $tone->getDescription());
        $this->assertSame('Respond in a calm, zen manner.', $tone->getSystemPrompt());
        $this->assertFalse($tone->isBuiltin());
        $this->assertFalse($tone->isActive());
        $this->assertSame(3, $tone->getSortOrder());
    }

    public function testToArray(): void
    {
        $tone = new SynapseTone();
        $tone->setKey('pro')
            ->setEmoji('💼')
            ->setName('Professional')
            ->setDescription('Business tone')
            ->setIsBuiltin(true)
            ->setIsActive(true);

        $arr = $tone->toArray();

        $this->assertSame('pro', $arr['key']);
        $this->assertSame('💼', $arr['emoji']);
        $this->assertSame('Professional', $arr['name']);
        $this->assertSame('Business tone', $arr['description']);
        $this->assertTrue($arr['isBuiltin']);
        $this->assertTrue($arr['isActive']);
    }
}
