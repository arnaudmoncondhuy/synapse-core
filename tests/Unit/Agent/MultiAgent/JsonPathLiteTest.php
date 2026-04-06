<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Agent\MultiAgent;

use ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\JsonPathLite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\JsonPathLite
 */
final class JsonPathLiteTest extends TestCase
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function validPathsProvider(): array
    {
        $state = [
            'inputs' => [
                'document' => 'Lorem ipsum',
                'user' => ['id' => 42, 'name' => 'Alice'],
            ],
            'steps' => [
                'analyze' => [
                    'output' => [
                        'text' => 'Analyse complète',
                        'data' => ['score' => 0.87],
                    ],
                ],
                'summarize' => [
                    'output' => [
                        'text' => 'Résumé final',
                    ],
                ],
            ],
        ];

        return [
            'root input scalar' => [$state, '$.inputs.document', 'Lorem ipsum'],
            'nested input' => [$state, '$.inputs.user.id', 42],
            'nested input string' => [$state, '$.inputs.user.name', 'Alice'],
            'step output text' => [$state, '$.steps.analyze.output.text', 'Analyse complète'],
            'step output nested data' => [$state, '$.steps.analyze.output.data.score', 0.87],
            'second step output' => [$state, '$.steps.summarize.output.text', 'Résumé final'],
            'returns whole object' => [$state, '$.inputs.user', ['id' => 42, 'name' => 'Alice']],
        ];
    }

    #[DataProvider('validPathsProvider')]
    public function testEvaluateResolvesValidPaths(array $state, string $path, mixed $expected): void
    {
        $this->assertSame($expected, JsonPathLite::evaluate($state, $path));
    }

    public function testEvaluateReturnsNullForMissingSegment(): void
    {
        $state = ['inputs' => ['document' => 'Lorem']];

        $this->assertNull(JsonPathLite::evaluate($state, '$.inputs.missing'));
        $this->assertNull(JsonPathLite::evaluate($state, '$.steps.any.output.x'));
    }

    public function testEvaluateReturnsNullWhenDescendingIntoScalar(): void
    {
        // $.inputs.document.subkey : document est une string, pas un array
        $state = ['inputs' => ['document' => 'Lorem']];
        $this->assertNull(JsonPathLite::evaluate($state, '$.inputs.document.subkey'));
    }

    public function testEvaluateThrowsOnMalformedPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonPathLite::evaluate([], 'inputs.document'); // manque $.
    }

    public function testEvaluateThrowsOnEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonPathLite::evaluate([], '$.');
    }

    public function testEvaluateThrowsOnDoubleDotPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JsonPathLite::evaluate([], '$.inputs..document');
    }

    public function testIsExpressionDetectsDollarPrefix(): void
    {
        $this->assertTrue(JsonPathLite::isExpression('$.inputs.x'));
        $this->assertTrue(JsonPathLite::isExpression('$.steps.a.output.y'));
        $this->assertFalse(JsonPathLite::isExpression('plain string'));
        $this->assertFalse(JsonPathLite::isExpression(''));
    }
}
