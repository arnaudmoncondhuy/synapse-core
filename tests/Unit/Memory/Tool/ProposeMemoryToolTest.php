<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Memory\Tool;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Memory\Tool\ProposeMemoryTool;
use PHPUnit\Framework\TestCase;

class ProposeMemoryToolTest extends TestCase
{
    private ProposeMemoryTool $tool;

    protected function setUp(): void
    {
        $this->tool = new ProposeMemoryTool();
    }

    public function testImplementsAiToolInterface(): void
    {
        $this->assertInstanceOf(AiToolInterface::class, $this->tool);
    }

    public function testGetNameReturnsExpectedValue(): void
    {
        $this->assertSame('propose_to_remember', $this->tool->getName());
    }

    public function testGetDescriptionIsNotEmpty(): void
    {
        $description = $this->tool->getDescription();

        $this->assertNotEmpty($description);
        $this->assertStringContainsString('mémoriser', $description);
    }

    public function testGetInputSchemaContainsFactProperty(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('fact', $schema['properties']);
        $this->assertContains('fact', $schema['required']);
    }

    public function testExecuteReturnsMemoryProposalAction(): void
    {
        $result = $this->tool->execute([
            'fact' => "L'utilisateur prefere le mode sombre",
            'category' => 'preference',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('memory_proposal', $result['__synapse_action']);
        $this->assertSame("L'utilisateur prefere le mode sombre", $result['fact']);
        $this->assertSame('preference', $result['category']);
        $this->assertSame('pending_user_confirmation', $result['status']);
    }

    public function testExecuteDefaultsCategoryToOther(): void
    {
        $result = $this->tool->execute(['fact' => 'Un fait quelconque']);

        $this->assertSame('other', $result['category']);
    }
}
