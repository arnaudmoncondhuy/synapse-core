<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\DefaultPermissionChecker;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class DefaultPermissionCheckerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // canView — sans security
    // -------------------------------------------------------------------------

    public function testCanViewReturnsFalseWithoutSecurity(): void
    {
        $checker = new DefaultPermissionChecker();
        $conv = $this->buildConversation('owner-1');

        $this->assertFalse($checker->canView($conv));
    }

    // -------------------------------------------------------------------------
    // canView — utilisateur non propriétaire
    // -------------------------------------------------------------------------

    public function testCanViewReturnsFalseWhenUserIsNotOwner(): void
    {
        $security = $this->mockSecurity($this->buildOwner('other-user'));
        $checker = new DefaultPermissionChecker(security: $security);

        $conv = $this->buildConversation('owner-1');

        $this->assertFalse($checker->canView($conv));
    }

    public function testCanViewReturnsFalseWhenUserIsNotConversationOwnerInterface(): void
    {
        $plainUser = $this->createStub(UserInterface::class);
        $security = $this->mockSecurity($plainUser);
        $checker = new DefaultPermissionChecker(security: $security);

        $conv = $this->buildConversation('owner-1');

        $this->assertFalse($checker->canView($conv));
    }

    // -------------------------------------------------------------------------
    // canView — propriétaire
    // -------------------------------------------------------------------------

    public function testCanViewReturnsTrueForOwner(): void
    {
        $owner = $this->buildOwner('owner-1');
        $security = $this->mockSecurity($owner);
        $checker = new DefaultPermissionChecker(security: $security);

        $conv = $this->buildConversation('owner-1', $owner);

        $this->assertTrue($checker->canView($conv));
    }

    // -------------------------------------------------------------------------
    // canView — admin
    // -------------------------------------------------------------------------

    public function testCanViewReturnsTrueForAdmin(): void
    {
        $otherUser = $this->buildOwner('other-user');
        $security = $this->mockSecurity($otherUser);

        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->with('ROLE_ADMIN')->willReturn(true);

        $checker = new DefaultPermissionChecker(security: $security, authChecker: $authChecker);
        $conv = $this->buildConversation('owner-1');

        $this->assertTrue($checker->canView($conv));
    }

    public function testCanViewReturnsFalseForNonAdmin(): void
    {
        $otherUser = $this->buildOwner('other-user');
        $security = $this->mockSecurity($otherUser);

        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(false);

        $checker = new DefaultPermissionChecker(security: $security, authChecker: $authChecker);
        $conv = $this->buildConversation('owner-1');

        $this->assertFalse($checker->canView($conv));
    }

    // -------------------------------------------------------------------------
    // canEdit — seul le propriétaire peut éditer (pas l'admin)
    // -------------------------------------------------------------------------

    public function testCanEditReturnsFalseWithoutSecurity(): void
    {
        $checker = new DefaultPermissionChecker();
        $this->assertFalse($checker->canEdit($this->buildConversation('owner-1')));
    }

    public function testCanEditReturnsTrueForOwner(): void
    {
        $owner = $this->buildOwner('owner-1');
        $security = $this->mockSecurity($owner);
        $checker = new DefaultPermissionChecker(security: $security);

        $conv = $this->buildConversation('owner-1', $owner);

        $this->assertTrue($checker->canEdit($conv));
    }

    public function testCanEditReturnsFalseForNonOwner(): void
    {
        $security = $this->mockSecurity($this->buildOwner('other'));
        $checker = new DefaultPermissionChecker(security: $security);

        $this->assertFalse($checker->canEdit($this->buildConversation('owner-1')));
    }

    // -------------------------------------------------------------------------
    // canDelete — délègue à canEdit
    // -------------------------------------------------------------------------

    public function testCanDeleteMatchesCanEdit(): void
    {
        $owner = $this->buildOwner('owner-1');
        $security = $this->mockSecurity($owner);
        $checker = new DefaultPermissionChecker(security: $security);

        $conv = $this->buildConversation('owner-1', $owner);

        $this->assertSame($checker->canEdit($conv), $checker->canDelete($conv));
    }

    // -------------------------------------------------------------------------
    // canAccessAdmin
    // -------------------------------------------------------------------------

    public function testCanAccessAdminReturnsFalseWithoutAuthChecker(): void
    {
        $checker = new DefaultPermissionChecker();
        $this->assertFalse($checker->canAccessAdmin());
    }

    public function testCanAccessAdminReturnsTrueWhenRoleGranted(): void
    {
        $authChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $checker = new DefaultPermissionChecker(authChecker: $authChecker);

        $this->assertTrue($checker->canAccessAdmin());
    }

    public function testCanAccessAdminSupportsCustomRole(): void
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->expects($this->once())
            ->method('isGranted')
            ->with('ROLE_SUPER_ADMIN')
            ->willReturn(true);

        $checker = new DefaultPermissionChecker(authChecker: $authChecker, adminRole: 'ROLE_SUPER_ADMIN');

        $this->assertTrue($checker->canAccessAdmin());
    }

    // -------------------------------------------------------------------------
    // canCreateConversation
    // -------------------------------------------------------------------------

    public function testCanCreateConversationReturnsTrueWithoutSecurity(): void
    {
        // Mode ouvert : pas de security = accès autorisé par défaut
        $checker = new DefaultPermissionChecker();
        $this->assertTrue($checker->canCreateConversation());
    }

    public function testCanCreateConversationReturnsTrueWhenLoggedIn(): void
    {
        $security = $this->mockSecurity($this->buildOwner('user-1'));
        $checker = new DefaultPermissionChecker(security: $security);

        $this->assertTrue($checker->canCreateConversation());
    }

    public function testCanCreateConversationReturnsFalseWhenAnonymous(): void
    {
        $security = $this->mockSecurity(null);
        $checker = new DefaultPermissionChecker(security: $security);

        $this->assertFalse($checker->canCreateConversation());
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function mockSecurity(?UserInterface $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    private function buildOwner(string $identifier): ConversationOwnerInterface
    {
        return new class($identifier) implements ConversationOwnerInterface, UserInterface {
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

            public function getRoles(): array
            {
                return [];
            }

            public function getPassword(): ?string
            {
                return null;
            }

            public function getSalt(): ?string
            {
                return null;
            }

            public function getUsername(): string
            {
                return $this->id;
            }

            public function getUserIdentifier(): string
            {
                return $this->id;
            }

            public function eraseCredentials(): void
            {
            }
        };
    }

    private function buildConversation(string $ownerId, ?ConversationOwnerInterface $ownerObject = null): SynapseConversation
    {
        $owner = $ownerObject ?? $this->buildOwner($ownerId);

        return new class($ownerId, $owner) extends SynapseConversation {
            public function __construct(
                private string $forcedOwnerId,
                private ConversationOwnerInterface $ownerEntity,
            ) {
                parent::__construct();
            }

            public function getId(): string
            {
                return 'conv-test';
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
}
