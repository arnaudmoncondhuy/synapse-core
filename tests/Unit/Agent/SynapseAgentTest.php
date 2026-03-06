<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use PHPUnit\Framework\TestCase;

class SynapseAgentTest extends TestCase
{
    public function testAsk(): void
    {
        $chatService = $this->createMock(ChatService::class);
        $preset = new SynapsePreset();
        $preset->setModel('gpt-4');

        $agent = new SynapseAgent(
            $chatService,
            $preset,
            'System Instruction',
            ['tool1'],
            3
        );

        $chatService->expects($this->once())
            ->method('ask')
            ->with(
                'hello',
                $this->callback(function ($options) use ($preset) {
                    return $options['stateless'] === true
                        && $options['preset'] === $preset
                        && $options['system_prompt'] === 'System Instruction'
                        && $options['tools_override'] === ['tool1']
                        && $options['max_turns'] === 3;
                })
            )
            ->willReturn(['answer' => 'hi']);

        $response = $agent->ask('hello');
        $this->assertSame('hi', $response['answer']);
    }
}
