<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared;

use ArnaudMoncondhuy\SynapseCore\Shared\Util\TextUtil;
use PHPUnit\Framework\TestCase;

class TextUtilTest extends TestCase
{
    // -------------------------------------------------------------------------
    // sanitizeUtf8()
    // -------------------------------------------------------------------------

    public function testValidUtf8IsReturnedAsIs(): void
    {
        $input = 'Bonjour le monde — café, naïve, Ω';
        $this->assertSame($input, TextUtil::sanitizeUtf8($input));
    }

    public function testEmptyStringReturnsEmpty(): void
    {
        $this->assertSame('', TextUtil::sanitizeUtf8(''));
    }

    public function testAsciiStringIsReturnedAsIs(): void
    {
        $input = 'Hello World 123 !@#$%^&*()';
        $this->assertSame($input, TextUtil::sanitizeUtf8($input));
    }

    public function testMultilineStringIsReturnedAsIs(): void
    {
        $input = "ligne 1\nligne 2\ttabulée";
        $this->assertSame($input, TextUtil::sanitizeUtf8($input));
    }

    public function testOutputIsAlwaysValidUtf8(): void
    {
        // Chaîne latin1 invalide en UTF-8
        $latin1 = "\xe9\xe0\xfc"; // é à ü en latin-1
        $result = TextUtil::sanitizeUtf8($latin1);

        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    // -------------------------------------------------------------------------
    // sanitizeArrayUtf8()
    // -------------------------------------------------------------------------

    public function testSanitizesSimpleFlatArray(): void
    {
        $input = ['key' => 'valeur valide'];
        $this->assertSame($input, TextUtil::sanitizeArrayUtf8($input));
    }

    public function testSanitizesNestedArray(): void
    {
        $input = [
            'niveau1' => [
                'niveau2' => 'texte',
                'nombre' => 42,
            ],
        ];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertSame('texte', $result['niveau1']['niveau2']);
        $this->assertSame(42, $result['niveau1']['nombre']);
    }

    public function testPreservesNonStringValues(): void
    {
        $input = [
            'int' => 42,
            'float' => 3.14,
            'bool' => true,
            'null' => null,
        ];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertSame(42, $result['int']);
        $this->assertSame(3.14, $result['float']);
        $this->assertTrue($result['bool']);
        $this->assertNull($result['null']);
    }

    public function testSanitizesStringKeys(): void
    {
        $input = ['cle_valide' => 'valeur'];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertArrayHasKey('cle_valide', $result);
    }

    public function testPreservesIntegerKeys(): void
    {
        $input = [0 => 'premier', 1 => 'second'];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey(1, $result);
    }

    public function testEmptyArrayReturnsEmptyArray(): void
    {
        $this->assertSame([], TextUtil::sanitizeArrayUtf8([]));
    }

    public function testAllOutputStringsAreValidUtf8(): void
    {
        $input = [
            'a' => 'texte valide',
            'nested' => ['b' => 'autre texte'],
        ];
        $result = TextUtil::sanitizeArrayUtf8($input);

        $this->assertTrue(mb_check_encoding($result['a'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($result['nested']['b'], 'UTF-8'));
    }
}
