<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Engine;

use ArnaudMoncondhuy\SynapseCore\Engine\PromptPipeline;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptBuildEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptCaptureEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptFinalizeEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptOptimizeEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PromptPipelineTest extends TestCase
{
    public function testPhasesAreDispatchedInOrder(): void
    {
        $dispatchedClasses = [];

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatchedClasses) {
            $dispatchedClasses[] = get_class($event);

            return $event;
        });

        $pipeline = new PromptPipeline($dispatcher);
        $pipeline->build('hello', []);

        $this->assertSame([
            PromptBuildEvent::class,
            PromptEnrichEvent::class,
            PromptOptimizeEvent::class,
            PromptFinalizeEvent::class,
            PromptCaptureEvent::class,
        ], $dispatchedClasses);
    }

    public function testEachPhaseReceivesPromptFromPreviousPhase(): void
    {
        $promptsReceived = [];

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$promptsReceived) {
            $promptsReceived[] = $event->getPrompt();

            // Phase BUILD construit le prompt
            if ($event instanceof PromptBuildEvent) {
                $event->setPrompt(['contents' => [['role' => 'system', 'content' => 'test']]]);
            }

            return $event;
        });

        $pipeline = new PromptPipeline($dispatcher);
        $pipeline->build('hello', []);

        // Phase ENRICH doit recevoir le prompt construit par BUILD
        $enrichPrompt = $promptsReceived[1];
        $this->assertSame('test', $enrichPrompt['contents'][0]['content']);
    }

    public function testConfigIsPassedBetweenPhases(): void
    {
        $configsReceived = [];

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$configsReceived) {
            $configsReceived[] = $event->getConfig();

            if ($event instanceof PromptBuildEvent) {
                $event->setConfig(SynapseRuntimeConfig::fromArray(['model' => 'gemini-flash', 'provider' => 'google']));
            }

            return $event;
        });

        $pipeline = new PromptPipeline($dispatcher);
        $result = $pipeline->build('hello', []);

        // Le config doit être propagé jusqu'à la fin
        $this->assertNotNull($result['config']);
        $this->assertSame('gemini-flash', $result['config']->model);
    }

    public function testReturnsFinalPromptAndConfig(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) {
            if ($event instanceof PromptBuildEvent) {
                $event->setPrompt(['contents' => [['role' => 'user', 'content' => 'bonjour']]]);
                $event->setConfig(SynapseRuntimeConfig::fromArray(['model' => 'gpt-4', 'provider' => 'openai']));
            }

            return $event;
        });

        $pipeline = new PromptPipeline($dispatcher);
        $result = $pipeline->build('bonjour', []);

        $this->assertArrayHasKey('prompt', $result);
        $this->assertArrayHasKey('config', $result);
        $this->assertSame('bonjour', $result['prompt']['contents'][0]['content']);
        $this->assertSame('gpt-4', $result['config']->model);
    }
}
