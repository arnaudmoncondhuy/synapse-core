<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseDebugLog;
use PHPUnit\Framework\TestCase;

class SynapseDebugLogTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $log = new SynapseDebugLog();

        $this->assertNull($log->getId());
        $this->assertNull($log->getConversationId());
        $this->assertNull($log->getModule());
        $this->assertNull($log->getModel());
        $this->assertNull($log->getTotalTokens());
        $this->assertSame([], $log->getData());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $log = new SynapseDebugLog();
        $now = new \DateTimeImmutable();

        $log->setDebugId('dbg-123')
            ->setConversationId('conv-456')
            ->setModule('chat')
            ->setModel('gemini-2.0-flash')
            ->setTotalTokens(1500)
            ->setCreatedAt($now)
            ->setData(['preset_config' => ['temperature' => 0.7], 'usage' => ['total' => 1500]]);

        $this->assertSame('dbg-123', $log->getDebugId());
        $this->assertSame('conv-456', $log->getConversationId());
        $this->assertSame('chat', $log->getModule());
        $this->assertSame('gemini-2.0-flash', $log->getModel());
        $this->assertSame(1500, $log->getTotalTokens());
        $this->assertSame($now, $log->getCreatedAt());
        $this->assertSame(0.7, $log->getData()['preset_config']['temperature']);
    }

    public function testNullableFieldsCanBeSetToNull(): void
    {
        $log = new SynapseDebugLog();
        $log->setConversationId('abc')
            ->setModule('chat')
            ->setModel('gpt-4')
            ->setTotalTokens(100);

        $log->setConversationId(null)
            ->setModule(null)
            ->setModel(null)
            ->setTotalTokens(null);

        $this->assertNull($log->getConversationId());
        $this->assertNull($log->getModule());
        $this->assertNull($log->getModel());
        $this->assertNull($log->getTotalTokens());
    }
}
