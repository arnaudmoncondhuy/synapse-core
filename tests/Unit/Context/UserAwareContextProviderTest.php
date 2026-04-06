<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Context;

use ArnaudMoncondhuy\SynapseCore\Context\UserAwareContextProvider;
use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;

class UserAwareContextProviderTest extends TestCase
{
    public function testGetSystemPromptWithoutUser(): void
    {
        $provider = $this->createConcreteProvider(null);

        $prompt = $provider->getSystemPrompt();

        $this->assertStringContainsString('Test assistant identity', $prompt);
        $this->assertStringContainsString('Nous sommes le', $prompt);
        $this->assertStringContainsString('Instructions', $prompt);
    }

    public function testGetSystemPromptIncludesUserContextWhenAuthenticated(): void
    {
        $user = $this->createStub(TestableConversationOwner::class);
        $user->method('getIdentifier')->willReturn('jean@example.com');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = $this->createConcreteProvider($security);

        $prompt = $provider->getSystemPrompt();

        $this->assertStringContainsString('jean@example.com', $prompt);
    }

    public function testGetInitialContextContainsDateAndTime(): void
    {
        $provider = $this->createConcreteProvider(null);

        $context = $provider->getInitialContext();

        $this->assertArrayHasKey('date', $context);
        $this->assertArrayHasKey('time', $context);
    }

    public function testGetInitialContextContainsUserWhenAuthenticated(): void
    {
        $user = $this->createStub(TestableConversationOwner::class);
        $user->method('getIdentifier')->willReturn('admin@test.fr');

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        $provider = $this->createConcreteProvider($security);

        $context = $provider->getInitialContext();

        $this->assertArrayHasKey('user', $context);
        $this->assertSame('admin@test.fr', $context['user']['identifier']);
    }

    private function createConcreteProvider(?Security $security): UserAwareContextProvider
    {
        return new class($security) extends UserAwareContextProvider {
            public function __construct(?Security $security)
            {
                parent::__construct($security, 'fr');
            }

            protected function getBaseIdentity(): string
            {
                return 'Test assistant identity';
            }
        };
    }
}

interface TestableConversationOwner extends ConversationOwnerInterface, UserInterface
{
}
