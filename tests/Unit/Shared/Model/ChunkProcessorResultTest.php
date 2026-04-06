<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use PHPUnit\Framework\TestCase;

class ChunkProcessorResultTest extends TestCase
{
    public function testClassExists(): void
    {
        $this->assertTrue(class_exists(\ArnaudMoncondhuy\SynapseCore\Shared\Model\ChunkProcessorResult::class));
    }
}
