<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object représentant la consommation de tokens d'un appel LLM.
 */
final readonly class TokenUsage
{
    public int $totalTokens;

    public function __construct(
        public int $promptTokens = 0,
        public int $completionTokens = 0,
        public int $thinkingTokens = 0,
    ) {
        $this->totalTokens = $promptTokens + $completionTokens + $thinkingTokens;
    }

    public function add(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
            $this->thinkingTokens + $other->thinkingTokens,
        );
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            promptTokens: is_numeric($data['prompt_tokens'] ?? null) ? (int) $data['prompt_tokens'] : 0,
            completionTokens: is_numeric($data['completion_tokens'] ?? null) ? (int) $data['completion_tokens'] : 0,
            thinkingTokens: is_numeric($data['thinking_tokens'] ?? null) ? (int) $data['thinking_tokens'] : 0,
        );
    }

    /**
     * @return array{prompt_tokens: int, completion_tokens: int, thinking_tokens: int, total_tokens: int}
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'thinking_tokens' => $this->thinkingTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
