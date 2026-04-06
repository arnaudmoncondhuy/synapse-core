<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Engine;

use ArnaudMoncondhuy\SynapseCore\Engine\ResponseFormatNormalizer;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\InvalidResponseFormatException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Engine\ResponseFormatNormalizer
 */
final class ResponseFormatNormalizerTest extends TestCase
{
    public function testAppliesStrictTrueByDefault(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $normalized = $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'weather',
                'schema' => ['type' => 'object', 'properties' => []],
            ],
        ]);

        $this->assertTrue($normalized['json_schema']['strict']);
    }

    public function testPreservesExplicitStrictFalse(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $normalized = $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'weather',
                'schema' => ['type' => 'object'],
                'strict' => false,
            ],
        ]);

        $this->assertFalse($normalized['json_schema']['strict']);
    }

    public function testAppliesDefaultNameWhenMissing(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $normalized = $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'schema' => ['type' => 'object'],
            ],
        ]);

        $this->assertSame('response', $normalized['json_schema']['name']);
    }

    public function testThrowsIfTypeIsNotJsonSchema(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $this->expectException(InvalidResponseFormatException::class);
        $this->expectExceptionMessage('response_format.type doit valoir "json_schema"');

        $normalizer->normalize([
            'type' => 'text',
            'json_schema' => ['schema' => ['type' => 'object']],
        ]);
    }

    public function testThrowsIfTypeMissing(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $this->expectException(InvalidResponseFormatException::class);

        $normalizer->normalize([
            'json_schema' => ['schema' => ['type' => 'object']],
        ]);
    }

    public function testThrowsIfJsonSchemaMissing(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $this->expectException(InvalidResponseFormatException::class);
        $this->expectExceptionMessage('response_format.json_schema est requis');

        $normalizer->normalize([
            'type' => 'json_schema',
        ]);
    }

    public function testThrowsIfSchemaMissing(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $this->expectException(InvalidResponseFormatException::class);
        $this->expectExceptionMessage('response_format.json_schema.schema est requis');

        $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'foo',
            ],
        ]);
    }

    public function testThrowsIfNameIsEmptyString(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $this->expectException(InvalidResponseFormatException::class);
        $this->expectExceptionMessage('response_format.json_schema.name doit être une chaîne non vide');

        $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => '',
                'schema' => ['type' => 'object'],
            ],
        ]);
    }

    public function testThrowsIfStrictIsNotBoolean(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $this->expectException(InvalidResponseFormatException::class);
        $this->expectExceptionMessage('response_format.json_schema.strict doit être un booléen');

        $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'foo',
                'schema' => ['type' => 'object'],
                'strict' => 'yes',
            ],
        ]);
    }

    public function testAcceptsMinimalValidSchema(): void
    {
        $normalizer = new ResponseFormatNormalizer();

        $schema = [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'temp' => ['type' => 'number'],
            ],
            'required' => ['city', 'temp'],
        ];

        $normalized = $normalizer->normalize([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'weather_report',
                'schema' => $schema,
            ],
        ]);

        $this->assertSame('json_schema', $normalized['type']);
        $this->assertSame('weather_report', $normalized['json_schema']['name']);
        $this->assertSame($schema, $normalized['json_schema']['schema']);
        $this->assertTrue($normalized['json_schema']['strict']);
    }
}
