<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\MemoryContextSubscriber;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use PHPUnit\Framework\TestCase;

class MemoryContextSubscriberTest extends TestCase
{
    private MemoryManager $memoryManager;

    protected function setUp(): void
    {
        $this->memoryManager = $this->createMock(MemoryManager::class);
    }

    private function buildEvent(string $message, array $options = [], array $contents = []): PromptEnrichEvent
    {
        if (empty($contents)) {
            $contents = [['role' => 'system', 'content' => 'System.'], ['role' => 'user', 'content' => $message]];
        }

        return new PromptEnrichEvent($message, $options, ['contents' => $contents]);
    }

    public function testSkipsWhenNoUserId(): void
    {
        $this->memoryManager->expects($this->never())->method('recall');

        $subscriber = new MemoryContextSubscriber($this->memoryManager);
        $event = $this->buildEvent('bonjour');
        $subscriber->onPrePrompt($event);

        // Le système ne doit pas avoir changé
        $this->assertSame('System.', $event->getPrompt()['contents'][0]['content']);
    }

    public function testInjectsMemoriesIntoSystemMessage(): void
    {
        $this->memoryManager->method('recall')->willReturn([
            ['content' => "L'utilisateur s'appelle Alice.", 'score' => 0.9],
            ['content' => "L'utilisateur habite à Lyon.", 'score' => 0.85],
        ]);

        $subscriber = new MemoryContextSubscriber($this->memoryManager);
        $event = $this->buildEvent('bonjour', ['user_id' => 'user_42']);
        $subscriber->onPrePrompt($event);

        $systemContent = $event->getPrompt()['contents'][0]['content'];
        $this->assertStringContainsString("L'utilisateur s'appelle Alice.", $systemContent);
        $this->assertStringContainsString("L'utilisateur habite à Lyon.", $systemContent);
    }

    public function testFiltersBelowThreshold(): void
    {
        $this->memoryManager->method('recall')->willReturn([
            ['content' => 'Mémoire pertinente.', 'score' => 0.85],
            ['content' => 'Mémoire non pertinente.', 'score' => 0.3], // sous le seuil 0.4
        ]);

        $subscriber = new MemoryContextSubscriber($this->memoryManager);
        $event = $this->buildEvent('bonjour', ['user_id' => 'user_42']);
        $subscriber->onPrePrompt($event);

        $systemContent = $event->getPrompt()['contents'][0]['content'];
        $this->assertStringContainsString('Mémoire pertinente.', $systemContent);
        $this->assertStringNotContainsString('Mémoire non pertinente.', $systemContent);
    }

    public function testSetsMetadataWhenNoMemories(): void
    {
        $this->memoryManager->method('recall')->willReturn([]);

        $subscriber = new MemoryContextSubscriber($this->memoryManager);
        $event = $this->buildEvent('bonjour', ['user_id' => 'user_42']);
        $subscriber->onPrePrompt($event);

        $metadata = $event->getPrompt()['metadata'] ?? [];
        $this->assertArrayHasKey('memory_matching', $metadata);
        $this->assertSame(0, $metadata['memory_matching']['found']);
    }

    public function testSkipsWhenMessageEmpty(): void
    {
        $this->memoryManager->expects($this->never())->method('recall');

        $subscriber = new MemoryContextSubscriber($this->memoryManager);
        $event = $this->buildEvent('', ['user_id' => 'user_42']);
        $subscriber->onPrePrompt($event);
    }

    public function testHandlesRecallExceptionGracefully(): void
    {
        $this->memoryManager->method('recall')->willThrowException(new \RuntimeException('DB error'));

        $subscriber = new MemoryContextSubscriber($this->memoryManager);
        $event = $this->buildEvent('bonjour', ['user_id' => 'user_42']);

        // Ne doit pas propager l'exception
        $subscriber->onPrePrompt($event);

        $metadata = $event->getPrompt()['metadata'] ?? [];
        $this->assertSame(0, $metadata['memory_matching']['found']);
        $this->assertNotNull($metadata['memory_matching']['error']);
    }
}
