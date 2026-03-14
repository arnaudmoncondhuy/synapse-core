<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Manager;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class ConversationManagerTest extends TestCase
{
    private EntityManagerInterface $em;
    private SynapseConversationRepository $repo;

    protected function setUp(): void
    {
        $this->em = $this->createStub(EntityManagerInterface::class);
        $this->repo = $this->createStub(SynapseConversationRepository::class);
    }

    // -------------------------------------------------------------------------
    // getConversation — bug fix : conversations supprimées
    // -------------------------------------------------------------------------

    public function testGetConversationReturnsNullWhenNotFound(): void
    {
        $this->repo->method('find')->willReturn(null);

        $manager = $this->buildManager();

        $this->assertNull($manager->getConversation('unknown-id'));
    }

    public function testGetConversationReturnsNullWhenDeleted(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $conversation->softDelete();

        $this->repo->method('find')->willReturn($conversation);

        $manager = $this->buildManager();

        // BUG FIX : une conversation soft-deletée ne doit pas être retournée
        $this->assertNull($manager->getConversation('conv-1'));
    }

    public function testGetConversationReturnsActiveConversation(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $this->repo->method('find')->willReturn($conversation);

        $manager = $this->buildManager();

        $this->assertSame($conversation, $manager->getConversation('conv-1'));
    }

    public function testGetConversationReturnsNullForArchivedWhenDeletedOnly(): void
    {
        // Une conversation archivée n'est PAS supprimée → doit être retournée
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $conversation->archive();

        $this->repo->method('find')->willReturn($conversation);

        $manager = $this->buildManager();

        $this->assertSame($conversation, $manager->getConversation('conv-1'));
    }

    // -------------------------------------------------------------------------
    // getConversation — ownership
    // -------------------------------------------------------------------------

    public function testGetConversationThrowsWhenOwnerMismatch(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-A');
        $this->repo->method('find')->willReturn($conversation);

        $otherOwner = $this->buildOwner('owner-B');

        $manager = $this->buildManager();

        $this->expectException(AccessDeniedException::class);
        $manager->getConversation('conv-1', $otherOwner);
    }

    public function testGetConversationPassesWhenOwnerMatches(): void
    {
        $owner = $this->buildOwner('owner-1');
        $conversation = $this->buildConversation('conv-1', 'owner-1', $owner);
        $this->repo->method('find')->willReturn($conversation);

        $manager = $this->buildManager();

        $this->assertSame($conversation, $manager->getConversation('conv-1', $owner));
    }

    public function testGetConversationSkipsOwnerCheckWhenOwnerIsNull(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $this->repo->method('find')->willReturn($conversation);

        $manager = $this->buildManager();

        // Sans owner fourni, pas de vérification d'ownership
        $this->assertSame($conversation, $manager->getConversation('conv-1'));
    }

    // -------------------------------------------------------------------------
    // getConversation — permissions
    // -------------------------------------------------------------------------

    public function testGetConversationThrowsWhenViewDenied(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $this->repo->method('find')->willReturn($conversation);

        $permissionChecker = $this->createMock(PermissionCheckerInterface::class);
        $permissionChecker->method('canView')->willReturn(false);

        $manager = $this->buildManager(permissionChecker: $permissionChecker);

        $this->expectException(AccessDeniedException::class);
        $manager->getConversation('conv-1');
    }

    public function testGetConversationPassesWhenViewGranted(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $this->repo->method('find')->willReturn($conversation);

        $permissionChecker = $this->createStub(PermissionCheckerInterface::class);
        $permissionChecker->method('canView')->willReturn(true);

        $manager = $this->buildManager(permissionChecker: $permissionChecker);

        $this->assertSame($conversation, $manager->getConversation('conv-1'));
    }

    // -------------------------------------------------------------------------
    // setCurrentConversation / getCurrentConversation
    // -------------------------------------------------------------------------

    public function testCurrentConversationIsNullByDefault(): void
    {
        $manager = $this->buildManager();

        $this->assertNull($manager->getCurrentConversation());
    }

    public function testSetAndGetCurrentConversation(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $manager = $this->buildManager();

        $manager->setCurrentConversation($conversation);

        $this->assertSame($conversation, $manager->getCurrentConversation());
    }

    public function testCurrentConversationCanBeReset(): void
    {
        $conversation = $this->buildConversation('conv-1', 'owner-1');
        $manager = $this->buildManager();

        $manager->setCurrentConversation($conversation);
        $manager->setCurrentConversation(null);

        $this->assertNull($manager->getCurrentConversation());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildManager(?PermissionCheckerInterface $permissionChecker = null): ConversationManager
    {
        return new ConversationManager(
            em: $this->em,
            conversationRepo: $this->repo,
            permissionChecker: $permissionChecker,
        );
    }

    private function buildConversation(string $id, string $ownerId, ?ConversationOwnerInterface $ownerObject = null): SynapseConversation
    {
        $owner = $ownerObject ?? $this->buildOwner($ownerId);

        return new class($id, $owner) extends SynapseConversation {
            public function __construct(
                private string $forcedId,
                private ConversationOwnerInterface $ownerEntity,
            ) {
                parent::__construct();
            }

            public function getId(): string
            {
                return $this->forcedId;
            }

            public function getOwner(): ?ConversationOwnerInterface
            {
                return $this->ownerEntity;
            }

            public function setOwner(ConversationOwnerInterface $owner): static
            {
                $this->ownerEntity = $owner;

                return $this;
            }
        };
    }

    private function buildOwner(string $id): ConversationOwnerInterface
    {
        return new class($id) implements ConversationOwnerInterface {
            public function __construct(private string $id)
            {
            }

            public function getId(): string|int|null
            {
                return $this->id;
            }

            public function getIdentifier(): string
            {
                return $this->id;
            }
        };
    }
}
