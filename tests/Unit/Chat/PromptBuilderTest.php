<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Chat;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Core\ToneRegistry;
use PHPUnit\Framework\TestCase;

class PromptBuilderTest extends TestCase
{
    private $contextProvider;
    private $toneRegistry;
    private $configProvider;
    private $promptBuilder;

    protected function setUp(): void
    {
        $this->contextProvider = $this->createMock(ContextProviderInterface::class);
        $this->toneRegistry = $this->createMock(ToneRegistry::class);
        $this->configProvider = $this->createMock(ConfigProviderInterface::class);

        $this->promptBuilder = new PromptBuilder(
            $this->contextProvider,
            $this->toneRegistry,
            $this->configProvider
        );
    }

    public function testBuildSystemInstructionWithConfigPrompt(): void
    {
        $this->configProvider->method('getConfig')->willReturn([
            'system_prompt' => 'Hello {NAME}, your email is {EMAIL}.',
        ]);
        $this->contextProvider->method('getInitialContext')->willReturn([
            'name' => 'Alice',
            'user' => [
                'email' => 'alice@example.com',
            ],
        ]);

        $instruction = $this->promptBuilder->buildSystemInstruction();

        $this->assertSame('Hello Alice, your email is alice@example.com.', $instruction);
    }

    public function testBuildSystemInstructionFallbackToContextProvider(): void
    {
        $this->configProvider->method('getConfig')->willReturn([]);
        $this->contextProvider->method('getSystemPrompt')->willReturn('Default system prompt');

        $instruction = $this->promptBuilder->buildSystemInstruction();

        $this->assertSame('Default system prompt', $instruction);
    }

    public function testBuildSystemInstructionWithTone(): void
    {
        $this->configProvider->method('getConfig')->willReturn([]);
        $this->contextProvider->method('getSystemPrompt')->willReturn('Base prompt');
        $this->toneRegistry->method('getSystemPrompt')->with('friendly')->willReturn('Be friendly.');

        $instruction = $this->promptBuilder->buildSystemInstruction('friendly');

        $this->assertStringContainsString('Base prompt', $instruction);
        $this->assertStringContainsString('Be friendly.', $instruction);
        $this->assertStringContainsString('🎭 TONE INSTRUCTIONS', $instruction);
    }

    public function testBuildSystemMessageFormat(): void
    {
        $this->configProvider->method('getConfig')->willReturn([]);
        $this->contextProvider->method('getSystemPrompt')->willReturn('Base prompt');

        $message = $this->promptBuilder->buildSystemMessage();

        $this->assertSame([
            'role' => 'system',
            'content' => 'Base prompt',
        ], $message);
    }
}
