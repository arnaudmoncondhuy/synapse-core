<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Security;

use ArnaudMoncondhuy\SynapseCore\Security\RoleProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Role\RoleHierarchy;

class RoleProviderTest extends TestCase
{
    public function testReturnsDefaultRolesWhenNoHierarchy(): void
    {
        $provider = new RoleProvider();

        $this->assertSame(['ROLE_USER', 'ROLE_ADMIN'], $provider->getAvailableRoles());
    }

    public function testExtractsRolesFromHierarchy(): void
    {
        $hierarchy = new RoleHierarchy([
            'ROLE_ADMIN' => ['ROLE_USER'],
            'ROLE_SUPER_ADMIN' => ['ROLE_ADMIN'],
        ]);

        $provider = new RoleProvider($hierarchy);
        $roles = $provider->getAvailableRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_SUPER_ADMIN', $roles);
    }

    public function testAlwaysIncludesUserAndAdmin(): void
    {
        $hierarchy = new RoleHierarchy([
            'ROLE_EDITOR' => ['ROLE_VIEWER'],
        ]);

        $provider = new RoleProvider($hierarchy);
        $roles = $provider->getAvailableRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_EDITOR', $roles);
        $this->assertContains('ROLE_VIEWER', $roles);
    }

    public function testRolesAreSorted(): void
    {
        $hierarchy = new RoleHierarchy([
            'ROLE_ZEBRA' => ['ROLE_ALPHA'],
        ]);

        $provider = new RoleProvider($hierarchy);
        $roles = $provider->getAvailableRoles();

        // ROLE_USER is unshifted to front, rest sorted
        $this->assertSame('ROLE_USER', $roles[0]);
    }
}
