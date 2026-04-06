<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\RgpdInfo;
use PHPUnit\Framework\TestCase;

class RgpdInfoTest extends TestCase
{
    public function testIsCompliant(): void
    {
        $info = new RgpdInfo('compliant', 'EU Provider', 'Hébergé en UE');
        $this->assertTrue($info->isCompliant());
        $this->assertFalse($info->isTolerated());
        $this->assertFalse($info->isAtRisk());
        $this->assertFalse($info->isWarning());
    }

    public function testIsTolerated(): void
    {
        $info = new RgpdInfo('tolerated', 'DPA signed', 'Société US avec DPA');
        $this->assertFalse($info->isCompliant());
        $this->assertTrue($info->isTolerated());
        $this->assertFalse($info->isAtRisk());
        $this->assertTrue($info->isWarning());
    }

    public function testIsAtRiskForRisk(): void
    {
        $info = new RgpdInfo('risk', 'Pays tiers', 'Protection adéquate');
        $this->assertTrue($info->isAtRisk());
        $this->assertTrue($info->isWarning());
    }

    public function testIsAtRiskForDanger(): void
    {
        $info = new RgpdInfo('danger', 'Danger', 'Pas de garanties');
        $this->assertTrue($info->isAtRisk());
        $this->assertTrue($info->isWarning());
    }

    public function testUnknownStatus(): void
    {
        $info = new RgpdInfo('unknown', 'Inconnu', 'Configuration incomplète');
        $this->assertFalse($info->isCompliant());
        $this->assertFalse($info->isTolerated());
        $this->assertFalse($info->isAtRisk());
        $this->assertTrue($info->isWarning());
    }

    public function testProperties(): void
    {
        $info = new RgpdInfo('compliant', 'Label', 'Explanation');
        $this->assertSame('compliant', $info->status);
        $this->assertSame('Label', $info->label);
        $this->assertSame('Explanation', $info->explanation);
    }
}
