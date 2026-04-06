<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use PHPUnit\Framework\TestCase;

class SynapseConversationTest extends TestCase
{
    private function createConversation(): SynapseConversation
    {
        return new class extends SynapseConversation {
            private $owner;

            public function getOwner(): ?ConversationOwnerInterface
            {
                return $this->owner;
            }

            public function setOwner(ConversationOwnerInterface $owner): static
            {
                $this->owner = $owner;

                return $this;
            }
        };
    }

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
        $conv = $this->createConversation();

        $this->assertNotEmpty($conv->getId());
        $this->assertNull($conv->getTitle());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conv->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $conv->getUpdatedAt());
        $this->assertSame(ConversationStatus::ACTIVE, $conv->getStatus());
        $this->assertNull($conv->getSummary());
        $this->assertNull($conv->getMetadata());
        $this->assertSame(0, $conv->getMessageCount());
        $this->assertTrue($conv->isActive());
        $this->assertFalse($conv->isArchived());
        $this->assertFalse($conv->isDeleted());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $conv = $this->createConversation();

        $conv->setTitle('My conversation')
            ->setSummary('A summary')
            ->setMetadata(['key' => 'value']);

        $this->assertSame('My conversation', $conv->getTitle());
        $this->assertSame('A summary', $conv->getSummary());
        $this->assertSame(['key' => 'value'], $conv->getMetadata());
    }

    public function testStatusTransitions(): void
    {
        $conv = $this->createConversation();

        $this->assertTrue($conv->isActive());

        $conv->archive();
        $this->assertTrue($conv->isArchived());
        $this->assertFalse($conv->isActive());

        $conv->softDelete();
        $this->assertTrue($conv->isDeleted());

        $conv->restore();
        $this->assertTrue($conv->isActive());
    }

    public function testRestoreOnlyWorksWhenDeleted(): void
    {
        $conv = $this->createConversation();
        $conv->archive();
        $conv->restore();
        // Should remain archived since restore only works from DELETED
        $this->assertTrue($conv->isArchived());
    }

    public function testAddRemoveMessages(): void
    {
        $conv = $this->createConversation();
        $msg = $this->createMessage();
        $msg->setRole(MessageRole::USER)->setContent('Hello');

        $conv->addMessage($msg);
        $this->assertSame(1, $conv->getMessageCount());

        // Adding same message again does nothing
        $conv->addMessage($msg);
        $this->assertSame(1, $conv->getMessageCount());

        $conv->removeMessage($msg);
        $this->assertSame(0, $conv->getMessageCount());
    }

    public function testIdIsUlidFormat(): void
    {
        $conv = $this->createConversation();
        // ULID is 26 chars
        $this->assertSame(26, strlen($conv->getId()));
    }
}
