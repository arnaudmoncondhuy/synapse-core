<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;
use PHPUnit\Framework\TestCase;

class ModelCapabilitiesTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $caps = new ModelCapabilities(model: 'gpt-4', provider: 'openai');

        $this->assertSame('gpt-4', $caps->model);
        $this->assertSame('openai', $caps->provider);
        $this->assertFalse($caps->supportsThinking);
        $this->assertTrue($caps->supportsFunctionCalling);
        $this->assertTrue($caps->supportsStreaming);
        $this->assertTrue($caps->supportsSystemPrompt);
        $this->assertFalse($caps->supportsVision);
        $this->assertSame('USD', $caps->currency);
        $this->assertNull($caps->contextWindow);
        $this->assertNull($caps->deprecatedAt);
        $this->assertNull($caps->rgpdRisk);
    }

    // ── supports() ──────────────────────────────────────────────────────

    public function testSupportsWithShortName(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsThinking: true);
        $this->assertTrue($caps->supports('thinking'));
    }

    public function testSupportsWithFullName(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsVision: true);
        $this->assertTrue($caps->supports('supports_vision'));
    }

    public function testSupportsReturnsFalseForUnknownCapability(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p');
        $this->assertFalse($caps->supports('unknown_capability'));
    }

    public function testSupportsEmbedding(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsEmbedding: true);
        $this->assertTrue($caps->supports('embedding'));
        $this->assertTrue($caps->supports('supports_embedding'));
    }

    public function testSupportsImageGeneration(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsImageGeneration: true);
        $this->assertTrue($caps->supports('image_generation'));
    }

    public function testSupportsParallelToolCalls(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsParallelToolCalls: true);
        $this->assertTrue($caps->supports('parallel_tool_calls'));
    }

    public function testSupportsResponseSchema(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsResponseSchema: true);
        $this->assertTrue($caps->supports('response_schema'));
    }

    public function testSupportsFunctionCallingDisabled(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsFunctionCalling: false);
        $this->assertFalse($caps->supports('function_calling'));
    }

    public function testSupportsStreamingDisabled(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsStreaming: false);
        $this->assertFalse($caps->supports('streaming'));
    }

    // ── getEffectiveMaxInputTokens() ────────────────────────────────────

    public function testGetEffectiveMaxInputTokensPreferMaxInput(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', maxInputTokens: 128000, contextWindow: 32000);
        $this->assertSame(128000, $caps->getEffectiveMaxInputTokens());
    }

    public function testGetEffectiveMaxInputTokensFallsBackToContextWindow(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', contextWindow: 32000);
        $this->assertSame(32000, $caps->getEffectiveMaxInputTokens());
    }

    public function testGetEffectiveMaxInputTokensReturnsNullWhenBothNull(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p');
        $this->assertNull($caps->getEffectiveMaxInputTokens());
    }

    // ── isDeprecated() ──────────────────────────────────────────────────

    public function testIsDeprecatedReturnsFalseWhenNoDate(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p');
        $this->assertFalse($caps->isDeprecated());
    }

    public function testIsDeprecatedReturnsTrueWhenPastDate(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', deprecatedAt: '2020-01-01');
        $this->assertTrue($caps->isDeprecated());
    }

    public function testIsDeprecatedReturnsFalseWhenFutureDate(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', deprecatedAt: '2099-12-31');
        $this->assertFalse($caps->isDeprecated());
    }

    public function testIsDeprecatedWithExplicitDate(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', deprecatedAt: '2025-06-15');
        $before = new \DateTimeImmutable('2025-06-14 00:00:00');
        $after = new \DateTimeImmutable('2025-06-16 00:00:00');

        $this->assertFalse($caps->isDeprecated($before));
        $this->assertTrue($caps->isDeprecated($after));
    }

    public function testIsDeprecatedWithInvalidFormat(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', deprecatedAt: 'not-a-date');
        $this->assertFalse($caps->isDeprecated());
    }

    // ── isRgpdSafe() ────────────────────────────────────────────────────

    public function testIsRgpdSafeWhenNull(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p');
        $this->assertTrue($caps->isRgpdSafe());
    }

    public function testIsRgpdSafeWhenDanger(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', rgpdRisk: 'danger');
        $this->assertFalse($caps->isRgpdSafe());
    }

    public function testIsRgpdSafeWhenTolerated(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', rgpdRisk: 'tolerated');
        $this->assertTrue($caps->isRgpdSafe());
    }

    // ── getRgpdMinStatus() ──────────────────────────────────────────────

    public function testGetRgpdMinStatusReturnsValue(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', rgpdRisk: 'risk');
        $this->assertSame('risk', $caps->getRgpdMinStatus());
    }

    // ── getAcceptedMimeTypes() ──────────────────────────────────────────

    public function testGetAcceptedMimeTypesWithExplicitList(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', acceptedMimeTypes: ['application/pdf']);
        $this->assertSame(['application/pdf'], $caps->getAcceptedMimeTypes());
    }

    public function testGetAcceptedMimeTypesFallbackForVision(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p', supportsVision: true);
        $types = $caps->getAcceptedMimeTypes();

        $this->assertContains('image/jpeg', $types);
        $this->assertContains('image/png', $types);
        $this->assertCount(4, $types);
    }

    public function testGetAcceptedMimeTypesEmptyWhenNoVision(): void
    {
        $caps = new ModelCapabilities(model: 'm', provider: 'p');
        $this->assertSame([], $caps->getAcceptedMimeTypes());
    }
}
