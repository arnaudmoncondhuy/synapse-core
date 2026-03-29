<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Event\MasterPromptSubscriber;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptFinalizeEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use PHPUnit\Framework\TestCase;

class MasterPromptSubscriberTest extends TestCase
{
    private ContextProviderInterface $contextProvider;
    private PromptBuilder $promptBuilder;
    private MasterPromptSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->contextProvider = $this->createStub(ContextProviderInterface::class);
        $this->contextProvider->method('getInitialContext')->willReturn([]);

        $this->promptBuilder = $this->createStub(PromptBuilder::class);
        $this->promptBuilder->method('interpolateVariables')->willReturnArgument(0);

        $this->subscriber = new MasterPromptSubscriber($this->contextProvider, $this->promptBuilder);
    }

    public function testInjectsMasterPromptIntoSystemMessage(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'test',
            'master_prompt' => 'Tu dois toujours répondre en français.',
            'master_prompt_stateless' => true,
        ]);

        $event = new PromptFinalizeEvent('bonjour', [], ['contents' => [
            ['role' => 'system', 'content' => 'System initial.'],
            ['role' => 'user', 'content' => 'bonjour'],
        ]], $config);

        $this->subscriber->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $this->assertStringContainsString('System initial.', $contents[0]['content']);
        $this->assertStringContainsString('Tu dois toujours répondre en français.', $contents[0]['content']);
        $this->assertStringContainsString('DIRECTIVE FONDAMENTALE', $contents[0]['content']);
    }

    public function testSkipsWhenNoMasterPrompt(): void
    {
        $config = SynapseRuntimeConfig::fromArray(['model' => 'test', 'master_prompt' => '']);

        $event = new PromptFinalizeEvent('msg', [], ['contents' => [
            ['role' => 'system', 'content' => 'System.'],
        ]], $config);

        $this->subscriber->onPrePrompt($event);

        $this->assertSame('System.', $event->getPrompt()['contents'][0]['content']);
    }

    public function testSkipsStatelessWhenMasterPromptStatelessFalse(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'test',
            'master_prompt' => 'Directive.',
            'master_prompt_stateless' => false,
        ]);

        $event = new PromptFinalizeEvent('msg', ['stateless' => true], ['contents' => [
            ['role' => 'system', 'content' => 'System.'],
        ]], $config);

        $this->subscriber->onPrePrompt($event);

        // Stateless call + stateless=false → master prompt non injecté
        $this->assertStringNotContainsString('Directive', $event->getPrompt()['contents'][0]['content']);
    }

    public function testCreatesSystemMessageWhenNoneExists(): void
    {
        $config = SynapseRuntimeConfig::fromArray([
            'model' => 'test',
            'master_prompt' => 'Directive fondamentale.',
        ]);

        $event = new PromptFinalizeEvent('msg', [], ['contents' => [
            ['role' => 'user', 'content' => 'question'],
        ]], $config);

        $this->subscriber->onPrePrompt($event);

        $contents = $event->getPrompt()['contents'];
        $this->assertSame('system', $contents[0]['role']);
        $this->assertStringContainsString('Directive fondamentale.', $contents[0]['content']);
    }
}
