<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Formatter;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use PHPUnit\Framework\TestCase;

class MessageFormatterTest extends TestCase
{
    private function buildFormatter(): MessageFormatter
    {
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encryption->method('isEncrypted')->willReturn(false);
        $encryption->method('encrypt')->willReturnArgument(0);
        $encryption->method('decrypt')->willReturnArgument(0);

        return new MessageFormatter($encryption);
    }

    public function testEntitiesToApiFormat(): void
    {
        $formatter = $this->buildFormatter();

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
        $formatter = $this->buildFormatter();
        $entities = [
            ['role' => 'user', 'content' => 'Raw message'],
        ];

        $result = $formatter->entitiesToApiFormat($entities);
        $this->assertSame('user', $result[0]['role']);
        $this->assertSame('Raw message', $result[0]['content']);
    }
}
