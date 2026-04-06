<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Util;

use ArnaudMoncondhuy\SynapseCore\Shared\Util\PromptUtil;
use PHPUnit\Framework\TestCase;

class PromptUtilTest extends TestCase
{
    public function testAppendToExistingSystemMessage(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'You are helpful.'],
            ['role' => 'user', 'content' => 'Hi'],
        ];

        $result = PromptUtil::appendToSystemMessage($messages, "\nExtra context.");

        $this->assertCount(2, $result);
        $this->assertSame("You are helpful.\nExtra context.", $result[0]['content']);
    }

    public function testCreatesSystemMessageWhenNoneExists(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'],
        ];

        $result = PromptUtil::appendToSystemMessage($messages, 'New system prompt.');

        $this->assertCount(2, $result);
        $this->assertSame('system', $result[0]['role']);
        $this->assertSame('New system prompt.', $result[0]['content']);
        $this->assertSame('user', $result[1]['role']);
    }

    public function testPrependedBlockIsTrimmedLeft(): void
    {
        $result = PromptUtil::appendToSystemMessage([], "\n  Leading whitespace.");

        $this->assertSame('Leading whitespace.', $result[0]['content']);
    }

    public function testEmptyMessagesArray(): void
    {
        $result = PromptUtil::appendToSystemMessage([], 'System content');

        $this->assertCount(1, $result);
        $this->assertSame('system', $result[0]['role']);
        $this->assertSame('System content', $result[0]['content']);
    }

    public function testAppendsToSystemWithNullContent(): void
    {
        $messages = [
            ['role' => 'system'],
        ];

        $result = PromptUtil::appendToSystemMessage($messages, 'Added.');

        $this->assertSame('Added.', $result[0]['content']);
    }

    public function testSystemMessageNotAtFirstPosition(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hi'],
            ['role' => 'system', 'content' => 'I am system.'],
            ['role' => 'assistant', 'content' => 'Hello'],
        ];

        $result = PromptUtil::appendToSystemMessage($messages, ' More.');

        $this->assertCount(3, $result);
        $this->assertSame('I am system. More.', $result[1]['content']);
    }
}
