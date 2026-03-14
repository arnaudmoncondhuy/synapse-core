<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Security\DefaultPermissionChecker;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Tests unitaires pour la vérification des permissions d'accès aux agents.
 */
class AgentPermissionCheckerTest extends TestCase
{
    private function createMockUser(string $identifier, array $roles = [])
    {
        $user = new class($identifier, $roles) implements ConversationOwnerInterface, UserInterface {
            public function __construct(private string $identifier, private array $roles)
            {
            }

            public function getId(): int
            {
                return 42;
            }

            public function getIdentifier(): string
            {
                return $this->identifier;
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }

            public function eraseCredentials(): void
            {
            }
        };

        return $user;
    }

    private function createMockAuthChecker(array $grantedRoles = []): AuthorizationCheckerInterface
    {
        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(
            fn (string $role) => in_array($role, $grantedRoles, true)
        );

        return $authChecker;
    }

    public function testCanUseAgentPublicAgentWithAccessControlNull(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(null);

        $security = $this->createMock(Security::class);
        $permissionChecker = new DefaultPermissionChecker($security);

        $this->assertTrue($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentPublicAgentWithEmptyAccessControl(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => [], 'userIdentifiers' => []]);

        $security = $this->createMock(Security::class);
        $permissionChecker = new DefaultPermissionChecker($security);

        $this->assertTrue($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentUserHasRole(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => ['ROLE_TEACHER', 'ROLE_ADMIN'], 'userIdentifiers' => []]);

        $user = $this->createMockUser('jean.dupont@example.com');
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $authChecker = $this->createMockAuthChecker(['ROLE_TEACHER']);
        $permissionChecker = new DefaultPermissionChecker($security, $authChecker);

        $this->assertTrue($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentUserHasNoRole(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => ['ROLE_TEACHER'], 'userIdentifiers' => []]);

        $user = $this->createMockUser('jean.dupont@example.com');
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $authChecker = $this->createMockAuthChecker(['ROLE_STUDENT']); // Rôle différent
        $permissionChecker = new DefaultPermissionChecker($security, $authChecker);

        $this->assertFalse($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentUserIdentifierMatches(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => [], 'userIdentifiers' => ['jean.dupont@example.com', 'marie.martin@example.com']]);

        $user = $this->createMockUser('jean.dupont@example.com');
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $authChecker = $this->createMockAuthChecker([]);
        $permissionChecker = new DefaultPermissionChecker($security, $authChecker);

        $this->assertTrue($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentUserIdentifierDoesNotMatch(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => [], 'userIdentifiers' => ['admin@example.com']]);

        $user = $this->createMockUser('jean.dupont@example.com');
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $authChecker = $this->createMockAuthChecker([]);
        $permissionChecker = new DefaultPermissionChecker($security, $authChecker);

        $this->assertFalse($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentUserHasRoleOrIdentifier(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => ['ROLE_ADMIN'], 'userIdentifiers' => ['beta@example.com']]);

        // Test avec rôle correspondant
        $user1 = $this->createMockUser('other@example.com');
        $security1 = $this->createMock(Security::class);
        $security1->method('getUser')->willReturn($user1);
        $authChecker1 = $this->createMockAuthChecker(['ROLE_ADMIN']);
        $permissionChecker1 = new DefaultPermissionChecker($security1, $authChecker1);
        $this->assertTrue($permissionChecker1->canUseAgent($agent));

        // Test avec identifiant correspondant
        $user2 = $this->createMockUser('beta@example.com');
        $security2 = $this->createMock(Security::class);
        $security2->method('getUser')->willReturn($user2);
        $authChecker2 = $this->createMockAuthChecker([]);
        $permissionChecker2 = new DefaultPermissionChecker($security2, $authChecker2);
        $this->assertTrue($permissionChecker2->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentNoAuthConfigured(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => ['ROLE_ADMIN'], 'userIdentifiers' => []]);

        $permissionChecker = new DefaultPermissionChecker();

        $this->assertFalse($permissionChecker->canUseAgent($agent));
    }

    public function testCanUseAgentRestrictedAgentNoUserConnected(): void
    {
        $agent = new SynapseAgent();
        $agent->setAccessControl(['roles' => ['ROLE_ADMIN'], 'userIdentifiers' => []]);

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $authChecker = $this->createMockAuthChecker([]);
        $permissionChecker = new DefaultPermissionChecker($security, $authChecker);

        $this->assertFalse($permissionChecker->canUseAgent($agent));
    }
}
