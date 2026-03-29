<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Event\RagContextSubscriber;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use PHPUnit\Framework\TestCase;

class RagContextSubscriberTest extends TestCase
{
    private RagManager $ragManager;
    private SynapseAgentRepository $agentRepository;
    private SynapseRagSourceRepository $ragSourceRepository;

    protected function setUp(): void
    {
        $this->ragManager = $this->createMock(RagManager::class);
        $this->agentRepository = $this->createMock(SynapseAgentRepository::class);
        $this->ragSourceRepository = $this->createMock(SynapseRagSourceRepository::class);
    }

    private function buildSubscriber(): RagContextSubscriber
    {
        return new RagContextSubscriber(
            $this->ragManager,
            $this->agentRepository,
            $this->ragSourceRepository,
        );
    }

    private function buildEvent(string $message, array $options = [], array $contents = []): PromptEnrichEvent
    {
        if (empty($contents)) {
            $contents = [['role' => 'system', 'content' => 'System.']];
        }

        return new PromptEnrichEvent($message, $options, ['contents' => $contents]);
    }

    public function testSkipsWhenNoAgentInOptions(): void
    {
        $this->agentRepository->expects($this->never())->method('findByKey');

        $event = $this->buildEvent('question', []);
        $this->buildSubscriber()->onPrePrompt($event);
    }

    public function testSkipsWhenAgentNotFound(): void
    {
        $this->agentRepository->method('findByKey')->willReturn(null);
        $this->ragManager->expects($this->never())->method('search');

        $event = $this->buildEvent('question', ['agent' => 'unknown_agent']);
        $this->buildSubscriber()->onPrePrompt($event);
    }

    public function testInjectsRagResultsIntoSystemMessage(): void
    {
        $agent = $this->createMock(\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent::class);
        $agent->method('getAllowedRagSources')->willReturn(['docs']);
        $agent->method('getRagMaxResults')->willReturn(5);
        $agent->method('getRagMinScore')->willReturn(0.6);

        $this->agentRepository->method('findByKey')->willReturn($agent);

        $source = $this->createMock(\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseRagSource::class);
        $source->method('getName')->willReturn('Documentation');
        $this->ragSourceRepository->method('findBySlug')->willReturn($source);

        $this->ragManager->method('search')->willReturn([
            ['content' => 'La réponse est 42.', 'score' => 0.9, 'sourceSlug' => 'docs'],
        ]);

        $event = $this->buildEvent('question', ['agent' => 'my_agent']);
        $this->buildSubscriber()->onPrePrompt($event);

        $systemContent = $event->getPrompt()['contents'][0]['content'];
        $this->assertStringContainsString('La réponse est 42.', $systemContent);
        $this->assertStringContainsString('Documentation', $systemContent);
        $this->assertStringContainsString('CONTEXTE DOCUMENTAIRE', $systemContent);
    }

    public function testSetsRagMetadata(): void
    {
        $agent = $this->createMock(\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent::class);
        $agent->method('getAllowedRagSources')->willReturn(['docs']);
        $agent->method('getRagMaxResults')->willReturn(5);
        $agent->method('getRagMinScore')->willReturn(0.6);

        $this->agentRepository->method('findByKey')->willReturn($agent);
        $this->ragSourceRepository->method('findBySlug')->willReturn(null);
        $this->ragManager->method('search')->willReturn([]);

        $event = $this->buildEvent('question', ['agent' => 'my_agent']);
        $this->buildSubscriber()->onPrePrompt($event);

        $metadata = $event->getPrompt()['metadata'] ?? [];
        $this->assertArrayHasKey('rag_matching', $metadata);
        $this->assertSame(0, $metadata['rag_matching']['found']);
        $this->assertSame(['docs'], $metadata['rag_matching']['sources_queried']);
    }

    public function testHandlesSearchExceptionGracefully(): void
    {
        $agent = $this->createMock(\ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent::class);
        $agent->method('getAllowedRagSources')->willReturn(['docs']);
        $agent->method('getRagMaxResults')->willReturn(5);
        $agent->method('getRagMinScore')->willReturn(0.6);

        $this->agentRepository->method('findByKey')->willReturn($agent);
        $this->ragManager->method('search')->willThrowException(new \RuntimeException('Vector DB down'));

        $event = $this->buildEvent('question', ['agent' => 'my_agent']);

        // Ne doit pas propager l'exception
        $this->buildSubscriber()->onPrePrompt($event);

        $metadata = $event->getPrompt()['metadata'] ?? [];
        $this->assertNotNull($metadata['rag_matching']['error']);
    }
}
