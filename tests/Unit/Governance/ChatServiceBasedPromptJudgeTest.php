<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance;

use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Governance\ChatServiceBasedPromptJudge;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\ChatServiceBasedPromptJudge
 */
final class ChatServiceBasedPromptJudgeTest extends TestCase
{
    public function testReturnsNullWhenPresetKeyEmpty(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);

        $judge = new ChatServiceBasedPromptJudge($chatService, $presetRepo, '');
        $result = $judge->judge($this->makeAgent(), 'new prompt', null);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenPresetNotFound(): void
    {
        $chatService = $this->createStub(ChatService::class);
        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn(null);

        $judge = new ChatServiceBasedPromptJudge($chatService, $presetRepo, 'judge_preset');
        $result = $judge->judge($this->makeAgent(), 'new prompt', null);

        $this->assertNull($result);
    }

    public function testBuildsJudgmentFromStructuredOutput(): void
    {
        $preset = new SynapseModelPreset();

        $chatService = $this->createMock(ChatService::class);
        $chatService->expects($this->once())
            ->method('ask')
            ->willReturnCallback(function (string $message, array $options) {
                // L'appelant doit activer le mode JSON (structured output).
                $this->assertArrayHasKey('response_format', $options);
                $this->assertSame('prompt_judge', $options['action'] ?? null);
                $this->assertSame('governance', $options['module'] ?? null);
                $this->assertTrue($options['stateless'] ?? false);

                return [
                    'answer' => '{"overall_score":7.2,"rationale":"clear","criteria":{}}',
                    'structured_output' => [
                        'overall_score' => 7.2,
                        'rationale' => 'clear',
                        'criteria' => ['clarity' => 8, 'specificity' => 7, 'safety' => 7, 'consistency' => 7],
                    ],
                    'model' => 'gemini-2.5-flash',
                ];
            });

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn($preset);

        $judge = new ChatServiceBasedPromptJudge($chatService, $presetRepo, 'judge_preset');
        $result = $judge->judge($this->makeAgent(), 'new prompt', 'old prompt');

        $this->assertNotNull($result);
        $this->assertSame(7.2, $result->score);
        $this->assertSame('clear', $result->rationale);
        $this->assertSame('model:gemini-2.5-flash', $result->judgedBy);
    }

    public function testClampsScoreToValidRange(): void
    {
        $preset = new SynapseModelPreset();

        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn([
            'answer' => '',
            'structured_output' => [
                'overall_score' => 42.0,
                'rationale' => 'weirdly high',
                'criteria' => [],
            ],
            'model' => 'test',
        ]);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn($preset);

        $judge = new ChatServiceBasedPromptJudge($chatService, $presetRepo, 'judge_preset');
        $result = $judge->judge($this->makeAgent(), 'prompt', null);

        $this->assertNotNull($result);
        $this->assertSame(10.0, $result->score);
    }

    public function testSwallowsExceptionsFromChatService(): void
    {
        $preset = new SynapseModelPreset();

        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willThrowException(new \RuntimeException('LLM timed out'));

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn($preset);

        $judge = new ChatServiceBasedPromptJudge($chatService, $presetRepo, 'judge_preset');
        $result = $judge->judge($this->makeAgent(), 'prompt', null);

        $this->assertNull($result);
    }

    public function testReturnsNullWhenStructuredOutputMalformed(): void
    {
        $preset = new SynapseModelPreset();

        $chatService = $this->createStub(ChatService::class);
        $chatService->method('ask')->willReturn([
            'answer' => '',
            'structured_output' => ['some_other_key' => 'nope'],
            'model' => 'test',
        ]);

        $presetRepo = $this->createStub(SynapseModelPresetRepository::class);
        $presetRepo->method('findOneBy')->willReturn($preset);

        $judge = new ChatServiceBasedPromptJudge($chatService, $presetRepo, 'judge_preset');
        $result = $judge->judge($this->makeAgent(), 'prompt', null);

        $this->assertNull($result);
    }

    private function makeAgent(): SynapseAgent
    {
        $agent = new SynapseAgent();
        $agent->setKey('support');
        $agent->setName('Support');
        $agent->setDescription('Agent de support client');

        return $agent;
    }
}
