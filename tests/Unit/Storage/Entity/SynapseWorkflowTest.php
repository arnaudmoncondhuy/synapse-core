<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow
 */
final class SynapseWorkflowTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $workflow = new SynapseWorkflow();

        $this->assertNull($workflow->getId());
        $this->assertSame('', $workflow->getWorkflowKey());
        $this->assertSame('', $workflow->getName());
        $this->assertNull($workflow->getDescription());
        $this->assertSame(['version' => 1, 'steps' => []], $workflow->getDefinition());
        $this->assertSame(1, $workflow->getVersion());
        $this->assertTrue($workflow->isActive());
        $this->assertFalse($workflow->isBuiltin());
        $this->assertSame(0, $workflow->getSortOrder());
        $this->assertInstanceOf(\DateTimeImmutable::class, $workflow->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $workflow->getUpdatedAt());
    }

    public function testGettersSettersRoundtrip(): void
    {
        $workflow = new SynapseWorkflow();
        $workflow
            ->setWorkflowKey('document_summary')
            ->setName('Résumé de document')
            ->setDescription('Analyse puis résumé')
            ->setDefinition(['version' => 1, 'steps' => [['name' => 'a', 'agent_name' => 'Agent']]])
            ->setIsActive(false)
            ->setIsBuiltin(true)
            ->setSortOrder(42);

        $this->assertSame('document_summary', $workflow->getWorkflowKey());
        $this->assertSame('Résumé de document', $workflow->getName());
        $this->assertSame('Analyse puis résumé', $workflow->getDescription());
        $this->assertCount(1, $workflow->getDefinition()['steps']);
        $this->assertFalse($workflow->isActive());
        $this->assertTrue($workflow->isBuiltin());
        $this->assertSame(42, $workflow->getSortOrder());
    }

    public function testGetStepsCountCountsSteps(): void
    {
        $workflow = new SynapseWorkflow();
        $this->assertSame(0, $workflow->getStepsCount());

        $workflow->setDefinition([
            'version' => 1,
            'steps' => [
                ['name' => 'step1', 'agent_name' => 'A'],
                ['name' => 'step2', 'agent_name' => 'B'],
                ['name' => 'step3', 'agent_name' => 'C'],
            ],
        ]);
        $this->assertSame(3, $workflow->getStepsCount());
    }

    public function testGetStepsCountReturnsZeroIfStepsMissingOrInvalid(): void
    {
        $workflow = new SynapseWorkflow();
        $workflow->setDefinition(['version' => 1]);
        $this->assertSame(0, $workflow->getStepsCount());

        $workflow->setDefinition(['version' => 1, 'steps' => 'invalid-string']);
        $this->assertSame(0, $workflow->getStepsCount());
    }

    public function testBumpVersionIfDefinitionChanged(): void
    {
        // Simule un PreUpdate avec 'definition' dans le changeset
        $workflow = new SynapseWorkflow();
        $this->assertSame(1, $workflow->getVersion());

        $args = $this->createMock(PreUpdateEventArgs::class);
        $args->method('hasChangedField')
            ->with('definition')
            ->willReturn(true);

        $workflow->bumpVersionIfDefinitionChanged($args);
        $this->assertSame(2, $workflow->getVersion());

        // Second bump
        $workflow->bumpVersionIfDefinitionChanged($args);
        $this->assertSame(3, $workflow->getVersion());
    }

    public function testVersionDoesNotBumpWhenDefinitionUnchanged(): void
    {
        // Cas critique : changement sur d'autres champs ne doit PAS bumper `version`.
        $workflow = new SynapseWorkflow();

        $args = $this->createMock(PreUpdateEventArgs::class);
        $args->method('hasChangedField')
            ->with('definition')
            ->willReturn(false);

        $workflow->bumpVersionIfDefinitionChanged($args);
        $this->assertSame(1, $workflow->getVersion());

        // Multiples appels sans changement → version reste à 1
        $workflow->bumpVersionIfDefinitionChanged($args);
        $workflow->bumpVersionIfDefinitionChanged($args);
        $this->assertSame(1, $workflow->getVersion());
    }
}
