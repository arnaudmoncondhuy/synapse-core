<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\SafetySettings;
use PHPUnit\Framework\TestCase;

class SafetySettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new SafetySettings();

        $this->assertFalse($settings->enabled);
        $this->assertSame('BLOCK_MEDIUM_AND_ABOVE', $settings->defaultThreshold);
        $this->assertSame([], $settings->thresholds);
    }

    public function testFromArrayWithData(): void
    {
        $settings = SafetySettings::fromArray([
            'enabled' => true,
            'default_threshold' => 'BLOCK_NONE',
            'thresholds' => ['HARM_CATEGORY_HARASSMENT' => 'BLOCK_LOW_AND_ABOVE'],
        ]);

        $this->assertTrue($settings->enabled);
        $this->assertSame('BLOCK_NONE', $settings->defaultThreshold);
        $this->assertArrayHasKey('HARM_CATEGORY_HARASSMENT', $settings->thresholds);
    }

    public function testFromArrayWithCamelCaseAlias(): void
    {
        $settings = SafetySettings::fromArray([
            'defaultThreshold' => 'BLOCK_ONLY_HIGH',
        ]);

        $this->assertSame('BLOCK_ONLY_HIGH', $settings->defaultThreshold);
    }

    public function testFromArrayWithEmptyData(): void
    {
        $settings = SafetySettings::fromArray([]);

        $this->assertFalse($settings->enabled);
        $this->assertSame('BLOCK_MEDIUM_AND_ABOVE', $settings->defaultThreshold);
    }
}
