<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use PHPUnit\Framework\TestCase;

class SynapseAgentTest extends TestCase
{
    public function testAsk(): void
    {
        $chatService = $this->createMock(ChatService::class);
        $preset = new SynapseModelPreset();
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
                    return true === $options['stateless']
                        && $options['preset'] === $preset
                        && 'System Instruction' === $options['system_prompt']
                        && $options['tools_override'] === ['tool1']
                        && 3 === $options['max_turns'];
                })
            )
            ->willReturn(['answer' => 'hi']);

        $response = $agent->ask('hello');
        $this->assertSame('hi', $response['answer']);
    }
}
