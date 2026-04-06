<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseVectorMemory;
use PHPUnit\Framework\TestCase;

class SynapseVectorMemoryTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $memory = new SynapseVectorMemory();

        $this->assertNull($memory->getId());
        $this->assertNull($memory->getEmbedding());
        $this->assertSame([], $memory->getPayload());
        $this->assertInstanceOf(\DateTimeImmutable::class, $memory->getCreatedAt());
        $this->assertNull($memory->getUserId());
        $this->assertSame('user', $memory->getScope());
        $this->assertNull($memory->getConversationId());
        $this->assertNull($memory->getContent());
        $this->assertSame('fact', $memory->getSourceType());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $memory = new SynapseVectorMemory();
        $embedding = [0.1, 0.2, 0.3];

        $memory->setEmbedding($embedding)
            ->setPayload(['text' => 'User likes cats', 'confidence' => 0.95])
            ->setUserId('user-42')
            ->setScope('conversation')
            ->setConversationId('conv-abc')
            ->setContent('User likes cats')
            ->setSourceType('preference');

        $this->assertSame($embedding, $memory->getEmbedding());
        $this->assertSame('User likes cats', $memory->getPayload()['text']);
        $this->assertSame('user-42', $memory->getUserId());
        $this->assertSame('conversation', $memory->getScope());
        $this->assertSame('conv-abc', $memory->getConversationId());
        $this->assertSame('User likes cats', $memory->getContent());
        $this->assertSame('preference', $memory->getSourceType());
    }

    public function testNullableFieldsCanBeReset(): void
    {
        $memory = new SynapseVectorMemory();
        $memory->setEmbedding([0.5])
            ->setUserId('uid')
            ->setConversationId('cid')
            ->setContent('text');

        $memory->setEmbedding(null)
            ->setUserId(null)
            ->setConversationId(null)
            ->setContent(null);

        $this->assertNull($memory->getEmbedding());
        $this->assertNull($memory->getUserId());
        $this->assertNull($memory->getConversationId());
        $this->assertNull($memory->getContent());
    }
}
