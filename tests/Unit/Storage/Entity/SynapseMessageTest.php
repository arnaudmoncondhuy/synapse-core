<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use PHPUnit\Framework\TestCase;

class SynapseMessageTest extends TestCase
{
    private function createMessage(): SynapseMessage
    {
        return new class extends SynapseMessage {
            private $conversation;

            public function getConversation(): SynapseConversation
            {
                return $this->conversation;
            }

            public function setConversation(SynapseConversation $conv): static
            {
                $this->conversation = $conv;

                return $this;
            }
        };
    }

    public function testDefaultValues(): void
    {
        $msg = $this->createMessage();

        $this->assertNotEmpty($msg->getId());
        $this->assertSame(26, strlen($msg->getId())); // ULID
        $this->assertInstanceOf(\DateTimeImmutable::class, $msg->getCreatedAt());
        $this->assertNull($msg->getPromptTokens());
        $this->assertNull($msg->getCompletionTokens());
        $this->assertNull($msg->getThinkingTokens());
        $this->assertNull($msg->getTotalTokens());
        $this->assertNull($msg->getFeedback());
        $this->assertNull($msg->getSafetyRatings());
        $this->assertFalse($msg->isBlocked());
        $this->assertNull($msg->getMetadata());
        $this->assertNull($msg->getLlmCallId());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $msg = $this->createMessage();

        $msg->setRole(MessageRole::MODEL)
            ->setContent('Hello world')
            ->setPromptTokens(100)
            ->setCompletionTokens(50)
            ->setThinkingTokens(20)
            ->setTotalTokens(170)
            ->setFeedback(1)
            ->setBlocked(true)
            ->setMetadata(['debug_id' => 'x'])
            ->setLlmCallId('call-123')
            ->setSafetyRatings(['HARM_CATEGORY_HATE_SPEECH' => ['category' => 'hate', 'probability' => 'LOW']]);

        $this->assertSame(MessageRole::MODEL, $msg->getRole());
        $this->assertSame('Hello world', $msg->getContent());
        $this->assertSame(100, $msg->getPromptTokens());
        $this->assertSame(50, $msg->getCompletionTokens());
        $this->assertSame(20, $msg->getThinkingTokens());
        $this->assertSame(170, $msg->getTotalTokens());
        $this->assertSame(1, $msg->getFeedback());
        $this->assertTrue($msg->isBlocked());
        $this->assertSame(['debug_id' => 'x'], $msg->getMetadata());
        $this->assertSame('call-123', $msg->getLlmCallId());
        $this->assertSame('LOW', $msg->getSafetyRatings()['HARM_CATEGORY_HATE_SPEECH']['probability']);
    }

    public function testRoleHelpers(): void
    {
        $msg = $this->createMessage();

        $msg->setRole(MessageRole::USER)->setContent('hi');
        $this->assertTrue($msg->isUser());
        $this->assertFalse($msg->isModel());
        $this->assertTrue($msg->isDisplayable());

        $msg->setRole(MessageRole::MODEL);
        $this->assertTrue($msg->isModel());
        $this->assertTrue($msg->isDisplayable());

        $msg->setRole(MessageRole::SYSTEM);
        $this->assertTrue($msg->isSystem());
        $this->assertFalse($msg->isDisplayable());

        $msg->setRole(MessageRole::FUNCTION);
        $this->assertTrue($msg->isFunction());
        $this->assertFalse($msg->isDisplayable());
    }

    public function testFeedbackMethods(): void
    {
        $msg = $this->createMessage();
        $msg->setRole(MessageRole::MODEL)->setContent('response');

        $msg->likeMessage();
        $this->assertSame(1, $msg->getFeedback());
        $this->assertSame('positive', $msg->getFeedbackRating());

        $msg->dislikeMessage();
        $this->assertSame(-1, $msg->getFeedback());
        $this->assertSame('negative', $msg->getFeedbackRating());

        $msg->resetFeedback();
        $this->assertNull($msg->getFeedback());
        $this->assertNull($msg->getFeedbackRating());
    }

    public function testCalculateTotalTokens(): void
    {
        $msg = $this->createMessage();
        $msg->setRole(MessageRole::MODEL)->setContent('test');

        $msg->setPromptTokens(100)
            ->setCompletionTokens(50)
            ->setThinkingTokens(30)
            ->calculateTotalTokens();

        $this->assertSame(180, $msg->getTotalTokens());

        // With null values
        $msg->setPromptTokens(null)->setCompletionTokens(null)->setThinkingTokens(null);
        $msg->calculateTotalTokens();
        $this->assertSame(0, $msg->getTotalTokens());
    }

    public function testDecryptedContent(): void
    {
        $msg = $this->createMessage();
        $msg->setRole(MessageRole::MODEL)->setContent('encrypted_blob');

        // Without decrypted content set, returns raw content
        $this->assertSame('encrypted_blob', $msg->getDecryptedContent());

        // With decrypted content set
        $msg->setDecryptedContent('Hello clear text');
        $this->assertSame('Hello clear text', $msg->getDecryptedContent());

        // Reset to null falls back to raw content
        $msg->setDecryptedContent(null);
        $this->assertSame('encrypted_blob', $msg->getDecryptedContent());
    }
}
