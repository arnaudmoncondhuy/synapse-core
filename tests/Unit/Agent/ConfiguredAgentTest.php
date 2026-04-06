<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;
use ArnaudMoncondhuy\SynapseCore\Agent\ConfiguredAgent;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\ConfiguredAgent
 */
final class ConfiguredAgentTest extends TestCase
{
    public function testCallDelegatesToChatServiceWithAgentKeyForced(): void
    {
        $entity = new SynapseAgent();
        $entity->setKey('support_client');
        $entity->setName('Support client');
        $entity->setDescription('Agent de support client');

        $chatService = $this->createMock(ChatService::class);
        $chatService->expects($this->once())
            ->method('ask')
            ->with(
                'Bonjour',
                $this->callback(static function (array $options): bool {
                    return 'support_client' === $options['agent']
                        && true === $options['debug'];
                }),
                [],
            )
            ->willReturn([
                'answer' => 'Comment puis-je vous aider ?',
                'debug_id' => 'dbg_123',
                'usage' => ['total_tokens' => 42],
                'safety' => [],
                'model' => 'gpt-4',
                'preset_id' => null,
                'agent_id' => 1,
                'generated_attachments' => [],
            ]);

        $agent = new ConfiguredAgent($entity, $chatService);

        $this->assertSame('support_client', $agent->getName());
        $this->assertSame('Agent de support client', $agent->getDescription());
        $this->assertSame($entity, $agent->getEntity());

        $output = $agent->call(Input::ofMessage('Bonjour'));

        $this->assertSame('Comment puis-je vous aider ?', $output->getAnswer());
        $this->assertSame('dbg_123', $output->getDebugId());
        $this->assertSame(['total_tokens' => 42], $output->getUsage());
    }

    public function testCallForcesAgentKeyEvenIfCallerOverrides(): void
    {
        $entity = new SynapseAgent();
        $entity->setKey('canonical_key');
        $entity->setName('X');
        $entity->setDescription('X');

        $chatService = $this->createMock(ChatService::class);
        $chatService->expects($this->once())
            ->method('ask')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $options): bool => 'canonical_key' === $options['agent']),
                $this->anything(),
            )
            ->willReturn([
                'answer' => 'ok',
                'debug_id' => null,
                'usage' => [],
                'safety' => [],
                'model' => '',
                'preset_id' => null,
                'agent_id' => null,
                'generated_attachments' => [],
            ]);

        $agent = new ConfiguredAgent($entity, $chatService);

        // Caller tries to override the agent key — the wrapper must ignore that.
        $agent->call(Input::ofMessage('hi'), ['agent' => 'attempted_override']);
    }

    public function testCallPassesAgentContextThroughOptions(): void
    {
        $entity = new SynapseAgent();
        $entity->setKey('ctx_agent');
        $entity->setName('X');
        $entity->setDescription('X');

        $ctx = AgentContext::root(userId: 'u1', origin: 'code');

        $chatService = $this->createMock(ChatService::class);
        $chatService->expects($this->once())
            ->method('ask')
            ->with(
                $this->anything(),
                $this->callback(static fn (array $options): bool => ($options['context'] ?? null) === $ctx),
                $this->anything(),
            )
            ->willReturn([
                'answer' => 'ok',
                'debug_id' => null,
                'usage' => [],
                'safety' => [],
                'model' => '',
                'preset_id' => null,
                'agent_id' => null,
                'generated_attachments' => [],
            ]);

        $agent = new ConfiguredAgent($entity, $chatService);
        $agent->call(Input::ofMessage('hi'), ['context' => $ctx]);
    }
}
