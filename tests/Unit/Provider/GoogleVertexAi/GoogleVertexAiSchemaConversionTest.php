<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Provider\GoogleVertexAi;

use ArnaudMoncondhuy\SynapseCore\Contract\ConfigProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Provider\GoogleVertexAi\GoogleVertexAiAuthService;
use ArnaudMoncondhuy\SynapseCore\Provider\GoogleVertexAi\GoogleVertexAiClient;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Couvre la conversion JSON Schema (format OpenAI / bundle) vers le format Gemini
 * (`generationConfig.responseSchema`). La méthode est privée, testée via réflexion.
 *
 * @covers \ArnaudMoncondhuy\SynapseCore\Provider\GoogleVertexAi\GoogleVertexAiClient
 */
#[AllowMockObjectsWithoutExpectations]
final class GoogleVertexAiSchemaConversionTest extends TestCase
{
    public function testConvertsTypesToUppercase(): void
    {
        $client = $this->makeClient();
        $schema = [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'temp' => ['type' => 'number'],
                'count' => ['type' => 'integer'],
                'active' => ['type' => 'boolean'],
            ],
        ];

        $converted = $this->invokeToGeminiSchema($client, $schema);

        $this->assertSame('OBJECT', $converted['type']);
        $this->assertSame('STRING', $converted['properties']['city']['type']);
        $this->assertSame('NUMBER', $converted['properties']['temp']['type']);
        $this->assertSame('INTEGER', $converted['properties']['count']['type']);
        $this->assertSame('BOOLEAN', $converted['properties']['active']['type']);
    }

    public function testStripsAdditionalPropertiesAndStrictAndName(): void
    {
        $client = $this->makeClient();
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'strict' => true,
            'name' => 'foo',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'properties' => [
                'a' => ['type' => 'string', 'additionalProperties' => false],
            ],
        ];

        $converted = $this->invokeToGeminiSchema($client, $schema);

        $this->assertArrayNotHasKey('additionalProperties', $converted);
        $this->assertArrayNotHasKey('strict', $converted);
        $this->assertArrayNotHasKey('name', $converted);
        $this->assertArrayNotHasKey('$schema', $converted);
        $this->assertArrayNotHasKey('additionalProperties', $converted['properties']['a']);
    }

    public function testRecursesIntoArrayItems(): void
    {
        $client = $this->makeClient();
        $schema = [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'name' => ['type' => 'string'],
                ],
            ],
        ];

        $converted = $this->invokeToGeminiSchema($client, $schema);

        $this->assertSame('ARRAY', $converted['type']);
        $this->assertSame('OBJECT', $converted['items']['type']);
        $this->assertArrayNotHasKey('additionalProperties', $converted['items']);
        $this->assertSame('STRING', $converted['items']['properties']['name']['type']);
    }

    public function testPreservesEnumAndRequiredAndDescription(): void
    {
        $client = $this->makeClient();
        $schema = [
            'type' => 'object',
            'description' => 'Un objet test',
            'required' => ['status'],
            'properties' => [
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'inactive'],
                    'description' => 'Statut courant',
                ],
            ],
        ];

        $converted = $this->invokeToGeminiSchema($client, $schema);

        $this->assertSame('Un objet test', $converted['description']);
        $this->assertSame(['status'], $converted['required']);
        $this->assertSame(['active', 'inactive'], $converted['properties']['status']['enum']);
        $this->assertSame('Statut courant', $converted['properties']['status']['description']);
    }

    private function makeClient(): GoogleVertexAiClient
    {
        return new GoogleVertexAiClient(
            $this->createMock(HttpClientInterface::class),
            $this->createMock(GoogleVertexAiAuthService::class),
            $this->createMock(ConfigProviderInterface::class),
            $this->createMock(ModelCapabilityRegistry::class),
        );
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    private function invokeToGeminiSchema(GoogleVertexAiClient $client, array $schema): array
    {
        $method = new \ReflectionMethod($client, 'toGeminiSchema');
        /** @var array<string, mixed> $result */
        $result = $method->invoke($client, $schema);

        return $result;
    }
}
