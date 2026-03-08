<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Engine\ContextTruncationService;
use PHPUnit\Framework\TestCase;

class ContextTruncationServiceTest extends TestCase
{
    private $service;

    protected function setUp(): void
    {
        $this->service = new ContextTruncationService();
    }

    public function testTruncateEmptyMessages(): void
    {
        $this->assertSame([], $this->service->truncate([], 2000));
    }

    public function testTruncatePreservesSystemAndLastMessageAcrossBudget(): void
    {
        $messages = [
            ['role' => 'system', 'content' => 'System prompt'], // 13 chars -> 4 tokens
            ['role' => 'user', 'content' => str_repeat('a', 4000)], // 4000 chars -> 1000 tokens
            ['role' => 'assistant', 'content' => 'Intermediate'], // 12 chars -> 3 tokens
            ['role' => 'user', 'content' => 'Last question'], // 13 chars -> 4 tokens
        ];

        // MaxTokens = 1000 + margin (1000) + system(4) + last(4) = 2008
        // Budget = 2008 - (4+4) - 1000 = 1000 tokens.
        // The intermediate message (3 tokens) + large message (1000 tokens) = 1003 tokens.
        // Since we go reverse:
        // 1. "Intermediate" (3) is kept. Budget becomes 997.
        // 2. Large message (1000) is NOT kept because 997 - 1000 < 0.

        $truncated = $this->service->truncate($messages, 2008);

        $this->assertCount(3, $truncated);
        $this->assertSame('system', $truncated[0]['role']);
        $this->assertSame('assistant', $truncated[1]['role']);
        $this->assertSame('user', $truncated[2]['role']);
        $this->assertSame('Last question', $truncated[2]['content']);
    }

    public function testEstimateTokensForContents(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'Hello'], // 5 chars -> 2 tokens (ceil(5/4)=2)
            ['role' => 'assistant', 'content' => 'Hi'], // 2 chars -> 1 token (ceil(2/4)=1)
        ];

        $tokens = $this->service->estimateTokensForContents($messages);
        $this->assertSame(3, $tokens);
    }

    public function testEstimateTokensWithToolCalls(): void
    {
        $messages = [
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    ['id' => '123', 'type' => 'function', 'function' => ['name' => 'test', 'arguments' => '{}']],
                ],
            ],
        ];

        // JSON of tool_calls will be counted.
        $tokens = $this->service->estimateTokensForContents($messages);
        $this->assertGreaterThan(0, $tokens);
    }
}
