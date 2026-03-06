<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Formatter;

use ArnaudMoncondhuy\SynapseCore\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use PHPUnit\Framework\TestCase;

class MessageFormatterTest extends TestCase
{
    public function testEntitiesToApiFormat(): void
    {
        $formatter = new MessageFormatter();

        $msg1 = $this->createMock(SynapseMessage::class);
        $msg1->method('getRole')->willReturn(MessageRole::USER);
        $msg1->method('getDecryptedContent')->willReturn('Hello');

        $msg2 = $this->createMock(SynapseMessage::class);
        $msg2->method('getRole')->willReturn(MessageRole::MODEL);
        $msg2->method('getDecryptedContent')->willReturn('Hi');

        $result = $formatter->entitiesToApiFormat([$msg1, $msg2]);

        $this->assertCount(2, $result);
        $this->assertSame('user', $result[0]['role']);
        $this->assertSame('Hello', $result[0]['content']);
        $this->assertSame('assistant', $result[1]['role']);
        $this->assertSame('Hi', $result[1]['content']);
    }

    public function testEntitiesToApiFormatWithRawArray(): void
    {
        $formatter = new MessageFormatter();
        $entities = [
            ['role' => 'user', 'content' => 'Raw message']
        ];

        $result = $formatter->entitiesToApiFormat($entities);
        $this->assertSame('user', $result[0]['role']);
        $this->assertSame('Raw message', $result[0]['content']);
    }
}
