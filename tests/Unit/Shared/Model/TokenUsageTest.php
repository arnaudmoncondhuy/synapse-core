<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use PHPUnit\Framework\TestCase;

class TokenUsageTest extends TestCase
{
    public function testConstructorComputesTotalTokens(): void
    {
        $usage = new TokenUsage(100, 50, 30);

        $this->assertSame(100, $usage->promptTokens);
        $this->assertSame(50, $usage->completionTokens);
        $this->assertSame(30, $usage->thinkingTokens);
        $this->assertSame(180, $usage->totalTokens);
    }

    public function testDefaultsAreZero(): void
    {
        $usage = new TokenUsage();

        $this->assertSame(0, $usage->promptTokens);
        $this->assertSame(0, $usage->completionTokens);
        $this->assertSame(0, $usage->thinkingTokens);
        $this->assertSame(0, $usage->totalTokens);
    }

    public function testAddCombinesBothUsages(): void
    {
        $a = new TokenUsage(100, 50, 10);
        $b = new TokenUsage(200, 80, 20);

        $result = $a->add($b);

        $this->assertSame(300, $result->promptTokens);
        $this->assertSame(130, $result->completionTokens);
        $this->assertSame(30, $result->thinkingTokens);
        $this->assertSame(460, $result->totalTokens);
    }

    public function testAddDoesNotMutateOriginal(): void
    {
        $a = new TokenUsage(10, 20, 30);
        $b = new TokenUsage(1, 2, 3);

        $a->add($b);

        $this->assertSame(10, $a->promptTokens);
    }

    public function testEmptyReturnsZeroUsage(): void
    {
        $usage = TokenUsage::empty();

        $this->assertSame(0, $usage->totalTokens);
    }

    public function testFromArrayWithValidData(): void
    {
        $usage = TokenUsage::fromArray([
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'thinking_tokens' => 25,
        ]);

        $this->assertSame(100, $usage->promptTokens);
        $this->assertSame(50, $usage->completionTokens);
        $this->assertSame(25, $usage->thinkingTokens);
        $this->assertSame(175, $usage->totalTokens);
    }

    public function testFromArrayWithMissingKeysDefaultsToZero(): void
    {
        $usage = TokenUsage::fromArray([]);

        $this->assertSame(0, $usage->promptTokens);
        $this->assertSame(0, $usage->completionTokens);
        $this->assertSame(0, $usage->thinkingTokens);
    }

    public function testFromArrayWithNonNumericValuesDefaultsToZero(): void
    {
        $usage = TokenUsage::fromArray([
            'prompt_tokens' => 'not-a-number',
            'completion_tokens' => null,
        ]);

        $this->assertSame(0, $usage->promptTokens);
        $this->assertSame(0, $usage->completionTokens);
    }

    public function testFromArraySplitsImageCompletionFromTextCompletion(): void
    {
        // Gemini renvoie candidatesTokenCount = 1358 (texte + image fusionnés) et
        // candidatesTokensDetails modality=IMAGE tokenCount = 1290.
        $usage = TokenUsage::fromArray([
            'prompt_tokens' => 10,
            'completion_tokens' => 1358,
            'image_completion_tokens' => 1290,
        ]);

        $this->assertSame(10, $usage->promptTokens);
        $this->assertSame(68, $usage->completionTokens, 'completionTokens doit être le texte pur (1358 - 1290)');
        $this->assertSame(1290, $usage->imageCompletionTokens);
        $this->assertSame(1368, $usage->totalTokens);
    }

    public function testFromArrayClampsNegativeTextCompletionToZero(): void
    {
        // Cas défensif : si image_completion_tokens > completion_tokens (anomalie provider),
        // on clamp à 0 au lieu de produire un texte négatif.
        $usage = TokenUsage::fromArray([
            'completion_tokens' => 100,
            'image_completion_tokens' => 150,
        ]);

        $this->assertSame(0, $usage->completionTokens);
        $this->assertSame(150, $usage->imageCompletionTokens);
    }

    public function testConstructorWithImageCompletionTokensIncludedInTotal(): void
    {
        $usage = new TokenUsage(
            promptTokens: 10,
            completionTokens: 20,
            thinkingTokens: 5,
            imageCompletionTokens: 1290,
        );

        $this->assertSame(1325, $usage->totalTokens);
    }

    public function testAddCombinesImageCompletionTokens(): void
    {
        $a = new TokenUsage(10, 20, 5, 100);
        $b = new TokenUsage(20, 30, 10, 200);

        $sum = $a->add($b);

        $this->assertSame(300, $sum->imageCompletionTokens);
        $this->assertSame(30 + 50 + 15 + 300, $sum->totalTokens);
    }

    public function testToArrayRoundtrip(): void
    {
        $usage = new TokenUsage(100, 50, 25);
        $array = $usage->toArray();

        $this->assertSame([
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'thinking_tokens' => 25,
            'total_tokens' => 175,
            'image_completion_tokens' => 0,
        ], $array);

        $restored = TokenUsage::fromArray($array);
        $this->assertSame($usage->totalTokens, $restored->totalTokens);
    }
}
