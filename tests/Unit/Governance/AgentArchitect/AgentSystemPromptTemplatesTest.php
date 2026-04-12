<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\AgentArchitect;

use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentSystemPromptTemplates;
use PHPUnit\Framework\TestCase;

class AgentSystemPromptTemplatesTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('useCaseProvider')]
    public function testGeneratesForEachUseCase(string $useCase, string $expectedKey, string $expectedEmoji): void
    {
        $result = AgentSystemPromptTemplates::generate($useCase, [], 'professionnel');

        $this->assertSame($expectedKey, $result['key']);
        $this->assertSame($expectedEmoji, $result['emoji']);
        $this->assertNotEmpty($result['name']);
        $this->assertNotEmpty($result['description']);
        $this->assertNotEmpty($result['system_prompt']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function useCaseProvider(): iterable
    {
        yield 'redaction' => ['redaction', 'agent_redaction', '✍️'];
        yield 'support' => ['support', 'agent_support', '🎧'];
        yield 'analyse' => ['analyse', 'agent_analyse', '🔍'];
        yield 'creatif' => ['creatif', 'agent_creatif', '🎨'];
        yield 'technique' => ['technique', 'agent_technique', '🔧'];
    }

    public function testUnknownUseCaseFallsBackToTechnique(): void
    {
        $result = AgentSystemPromptTemplates::generate('unknown', [], 'professionnel');

        $this->assertSame('agent_technique', $result['key']);
    }

    public function testToolsCapabilityAddsSection(): void
    {
        $without = AgentSystemPromptTemplates::generate('redaction', [], 'professionnel');
        $with = AgentSystemPromptTemplates::generate('redaction', ['tools'], 'professionnel');

        $this->assertStringNotContainsString('function calling', $without['system_prompt']);
        $this->assertStringContainsString('function calling', $with['system_prompt']);
        $this->assertStringContainsString('outils', $with['description']);
    }

    public function testRagCapabilityAddsSection(): void
    {
        $without = AgentSystemPromptTemplates::generate('redaction', [], 'professionnel');
        $with = AgentSystemPromptTemplates::generate('redaction', ['rag'], 'professionnel');

        $this->assertStringNotContainsString('recherche sémantique', $without['system_prompt']);
        $this->assertStringContainsString('recherche sémantique', $with['system_prompt']);
        $this->assertStringContainsString('documents', $with['description']);
    }

    public function testThinkingCapabilityAddsSection(): void
    {
        $with = AgentSystemPromptTemplates::generate('analyse', ['thinking'], 'professionnel');

        $this->assertStringContainsString('réflexion approfondie', $with['system_prompt']);
        $this->assertStringContainsString('réflexion approfondie', $with['description']);
    }

    public function testMultipleCapabilities(): void
    {
        $result = AgentSystemPromptTemplates::generate('support', ['tools', 'rag', 'thinking'], 'decontracte');

        $this->assertStringContainsString('function calling', $result['system_prompt']);
        $this->assertStringContainsString('recherche sémantique', $result['system_prompt']);
        $this->assertStringContainsString('réflexion approfondie', $result['system_prompt']);
        $this->assertStringContainsString('outils', $result['description']);
        $this->assertStringContainsString('documents', $result['description']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('toneProvider')]
    public function testToneDirectiveIncluded(string $tone, string $expectedPhrase): void
    {
        $result = AgentSystemPromptTemplates::generate('redaction', [], $tone);

        $this->assertStringContainsString($expectedPhrase, $result['system_prompt']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function toneProvider(): iterable
    {
        yield 'professionnel' => ['professionnel', 'professionnel'];
        yield 'decontracte' => ['decontracte', 'amical'];
        yield 'pedagogique' => ['pedagogique', 'pédagogique'];
    }

    public function testSecuritySectionAlwaysPresent(): void
    {
        $result = AgentSystemPromptTemplates::generate('creatif', [], 'decontracte');

        $this->assertStringContainsString('Sécurité', $result['system_prompt']);
        $this->assertStringContainsString('hors scope', $result['system_prompt']);
    }

    public function testResultHasAllRequiredKeys(): void
    {
        $result = AgentSystemPromptTemplates::generate('analyse', ['tools'], 'pedagogique');

        $this->assertArrayHasKey('key', $result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('emoji', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayHasKey('system_prompt', $result);
    }
}
