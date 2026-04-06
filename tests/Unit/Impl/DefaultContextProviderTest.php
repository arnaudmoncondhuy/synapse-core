<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Impl;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Impl\DefaultContextProvider;
use PHPUnit\Framework\TestCase;

class DefaultContextProviderTest extends TestCase
{
    private DefaultContextProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new DefaultContextProvider();
    }

    public function testImplementsContextProviderInterface(): void
    {
        $this->assertInstanceOf(ContextProviderInterface::class, $this->provider);
    }

    public function testGetSystemPromptContainsCurrentDate(): void
    {
        $prompt = $this->provider->getSystemPrompt();

        $expectedDate = (new \DateTimeImmutable())->format('d/m/Y');
        $this->assertStringContainsString($expectedDate, $prompt);
        $this->assertStringContainsString('Date et heure actuelles', $prompt);
    }

    public function testGetInitialContextReturnsEmptyArray(): void
    {
        $this->assertSame([], $this->provider->getInitialContext());
    }
}
