<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseLlmCall;
use PHPUnit\Framework\TestCase;

class SynapseLlmCallTest extends TestCase
{
    public function testCallIdIsGeneratedAutomatically(): void
    {
        $call = new SynapseLlmCall();

        $callId = $call->getCallId();
        $this->assertIsString($callId);
        $this->assertNotEmpty($callId);
    }

    public function testCallIdMatchesUuidFormat(): void
    {
        $call = new SynapseLlmCall();
        $call->setModule('chat');
        $call->setAction('ask');
        $call->setModel('gemini-2.0-flash');
        $call->setPromptTokens(100);
        $call->setCompletionTokens(50);

        $callId = $call->getCallId();
        // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
        $uuidRegex = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        $this->assertMatchesRegularExpression($uuidRegex, $callId);
    }

    public function testCalculateTotalTokens_sumsProperly(): void
    {
        $call = new SynapseLlmCall();
        $call->setModule('chat');
        $call->setAction('ask');
        $call->setModel('gemini-2.0-flash');
        $call->setPromptTokens(100);
        $call->setCompletionTokens(50);
        $call->setThinkingTokens(10);

        $call->calculateTotalTokens();
        $this->assertSame(160, $call->getTotalTokens());
    }

    public function testCalculateTotalTokens_withZeroThinking(): void
    {
        $call = new SynapseLlmCall();
        $call->setModule('chat');
        $call->setAction('ask');
        $call->setModel('gemini-2.0-flash');
        $call->setPromptTokens(100);
        $call->setCompletionTokens(50);
        $call->setThinkingTokens(0);

        $call->calculateTotalTokens();
        $this->assertSame(150, $call->getTotalTokens());
    }

    public function testCalculateTotalTokens_withOnlyPromptTokens(): void
    {
        $call = new SynapseLlmCall();
        $call->setModule('chat');
        $call->setAction('ask');
        $call->setModel('gemini-2.0-flash');
        $call->setPromptTokens(1000);
        $call->setCompletionTokens(0);
        $call->setThinkingTokens(0);

        $call->calculateTotalTokens();
        $this->assertSame(1000, $call->getTotalTokens());
    }

    public function testCreatedAtIsSetOnConstruction(): void
    {
        $before = new \DateTimeImmutable();
        $call = new SynapseLlmCall();
        $call->setModule('chat');
        $call->setAction('ask');
        $call->setModel('gemini-2.0-flash');
        $call->setPromptTokens(100);
        $call->setCompletionTokens(50);
        $after = new \DateTimeImmutable();

        $createdAt = $call->getCreatedAt();
        $this->assertInstanceOf(\DateTimeImmutable::class, $createdAt);
        $this->assertGreaterThanOrEqual($before, $createdAt);
        $this->assertLessThanOrEqual($after, $createdAt);
    }

    public function testMultipleCallIds_areUnique(): void
    {
        $call1 = new SynapseLlmCall();
        $call1->setModule('chat');
        $call1->setAction('ask');
        $call1->setModel('gemini-2.0-flash');
        $call1->setPromptTokens(100);
        $call1->setCompletionTokens(50);

        $call2 = new SynapseLlmCall();
        $call2->setModule('chat');
        $call2->setAction('ask');
        $call2->setModel('gemini-2.0-flash');
        $call2->setPromptTokens(100);
        $call2->setCompletionTokens(50);

        $this->assertNotEquals($call1->getCallId(), $call2->getCallId());
    }
}
