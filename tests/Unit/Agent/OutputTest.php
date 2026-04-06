<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\Output;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\Output
 */
final class OutputTest extends TestCase
{
    public function testFromChatServiceResultHydratesData(): void
    {
        $result = [
            'answer' => '{"city":"Lyon"}',
            'debug_id' => 'dbg_1',
            'usage' => ['total_tokens' => 42],
            'safety' => [],
            'model' => 'gemini-2.5-flash',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
            'structured_output' => ['city' => 'Lyon', 'temp' => 22.5],
        ];

        $output = Output::fromChatServiceResult($result);

        $this->assertSame('{"city":"Lyon"}', $output->getAnswer());
        $this->assertSame(['city' => 'Lyon', 'temp' => 22.5], $output->getData());
        $this->assertSame('dbg_1', $output->getDebugId());
        $this->assertSame(['total_tokens' => 42], $output->getUsage());
    }

    public function testFromChatServiceResultWithoutStructuredOutputHasEmptyData(): void
    {
        $result = [
            'answer' => 'Bonjour',
            'debug_id' => null,
            'usage' => [],
            'safety' => [],
            'model' => 'gpt-4',
            'preset_id' => null,
            'agent_id' => null,
            'generated_attachments' => [],
        ];

        $output = Output::fromChatServiceResult($result);

        $this->assertSame([], $output->getData());
        $this->assertSame('Bonjour', $output->getAnswer());
    }

    public function testFromChatServiceResultCopiesMetadata(): void
    {
        $result = [
            'answer' => 'ok',
            'debug_id' => null,
            'usage' => [],
            'safety' => [['category' => 'HARASSMENT']],
            'model' => 'gpt-4',
            'preset_id' => 7,
            'agent_id' => 3,
            'generated_attachments' => [],
        ];

        $output = Output::fromChatServiceResult($result);

        $metadata = $output->getMetadata();
        $this->assertSame('gpt-4', $metadata['model']);
        $this->assertSame(7, $metadata['preset_id']);
        $this->assertSame(3, $metadata['agent_id']);
        $this->assertSame([['category' => 'HARASSMENT']], $metadata['safety']);
    }
}
