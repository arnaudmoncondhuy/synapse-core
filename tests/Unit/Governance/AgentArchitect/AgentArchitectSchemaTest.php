<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Governance\AgentArchitect;

use ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitectSchema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect\AgentArchitectSchema
 */
final class AgentArchitectSchemaTest extends TestCase
{
    public function testCreateAgentSchemaHasRequiredStructure(): void
    {
        $schema = AgentArchitectSchema::createAgent();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame('architect_create_agent', $schema['json_schema']['name']);
        $this->assertTrue($schema['json_schema']['strict']);

        $props = $schema['json_schema']['schema']['properties'];
        $this->assertArrayHasKey('key', $props);
        $this->assertArrayHasKey('name', $props);
        $this->assertArrayHasKey('emoji', $props);
        $this->assertArrayHasKey('description', $props);
        $this->assertArrayHasKey('system_prompt', $props);
        $this->assertArrayHasKey('reasoning', $props);

        $required = $schema['json_schema']['schema']['required'];
        $this->assertContains('key', $required);
        $this->assertContains('system_prompt', $required);
    }

    public function testImprovePromptSchemaHasRequiredStructure(): void
    {
        $schema = AgentArchitectSchema::improvePrompt();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame('architect_improve_prompt', $schema['json_schema']['name']);

        $props = $schema['json_schema']['schema']['properties'];
        $this->assertArrayHasKey('new_system_prompt', $props);
        $this->assertArrayHasKey('changes_summary', $props);
        $this->assertArrayHasKey('reasoning', $props);

        $required = $schema['json_schema']['schema']['required'];
        $this->assertContains('new_system_prompt', $required);
        $this->assertContains('changes_summary', $required);
    }

    public function testCreateWorkflowSchemaHasRequiredStructure(): void
    {
        $schema = AgentArchitectSchema::createWorkflow();

        $this->assertSame('json_schema', $schema['type']);
        $this->assertSame('architect_create_workflow', $schema['json_schema']['name']);

        $props = $schema['json_schema']['schema']['properties'];
        $this->assertArrayHasKey('key', $props);
        $this->assertArrayHasKey('name', $props);
        $this->assertArrayHasKey('steps', $props);
        $this->assertArrayHasKey('reasoning', $props);

        // steps est un array d'objets avec name + agent_name requis
        $stepProps = $props['steps']['items']['properties'];
        $this->assertArrayHasKey('name', $stepProps);
        $this->assertArrayHasKey('agent_name', $stepProps);
        $stepRequired = $props['steps']['items']['required'];
        $this->assertContains('name', $stepRequired);
        $this->assertContains('agent_name', $stepRequired);
    }

    public function testForActionReturnsCorrectSchema(): void
    {
        $this->assertSame(
            AgentArchitectSchema::createAgent(),
            AgentArchitectSchema::forAction('create_agent'),
        );
        $this->assertSame(
            AgentArchitectSchema::improvePrompt(),
            AgentArchitectSchema::forAction('improve_prompt'),
        );
        $this->assertSame(
            AgentArchitectSchema::createWorkflow(),
            AgentArchitectSchema::forAction('create_workflow'),
        );
    }

    public function testForActionThrowsOnUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/inconnue/');

        AgentArchitectSchema::forAction('invalid_action');
    }

    #[DataProvider('provideAllSchemas')]
    public function testAllSchemasAreValidJsonSchemaFormat(string $action): void
    {
        $schema = AgentArchitectSchema::forAction($action);

        // Structure OpenAI response_format canonique
        $this->assertArrayHasKey('type', $schema);
        $this->assertArrayHasKey('json_schema', $schema);
        $this->assertArrayHasKey('name', $schema['json_schema']);
        $this->assertArrayHasKey('schema', $schema['json_schema']);
        $this->assertArrayHasKey('strict', $schema['json_schema']);
        $this->assertArrayHasKey('type', $schema['json_schema']['schema']);
        $this->assertSame('object', $schema['json_schema']['schema']['type']);
        $this->assertArrayHasKey('properties', $schema['json_schema']['schema']);
        $this->assertArrayHasKey('required', $schema['json_schema']['schema']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAllSchemas(): iterable
    {
        yield 'create_agent' => ['create_agent'];
        yield 'improve_prompt' => ['improve_prompt'];
        yield 'create_workflow' => ['create_workflow'];
    }
}
