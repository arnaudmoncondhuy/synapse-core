<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object représentant un chunk normalisé retourné par un LLM client.
 */
final readonly class NormalizedChunk
{
    public function __construct(
        public ?string $text = null,
        public ?string $thinking = null,
        /** @var list<array{name: string, args: array<string, mixed>, id?: string}> */
        public array $functionCalls = [],
        public TokenUsage $usage = new TokenUsage(),
        /** @var array<int, array<string, mixed>> */
        public array $safetyRatings = [],
        public bool $blocked = false,
        public ?string $blockedReason = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            text: $data['text'] ?? null,
            thinking: $data['thinking'] ?? null,
            functionCalls: $data['function_calls'] ?? [],
            usage: TokenUsage::fromArray($data['usage'] ?? []),
            safetyRatings: $data['safety_ratings'] ?? [],
            blocked: (bool) ($data['blocked'] ?? false),
            blockedReason: $data['blocked_reason'] ?? null,
        );
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->functionCalls;
    }

    public function isBlocked(): bool
    {
        return $this->blocked;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'thinking' => $this->thinking,
            'function_calls' => $this->functionCalls,
            'usage' => $this->usage->toArray(),
            'safety_ratings' => $this->safetyRatings,
            'blocked' => $this->blocked,
            'blocked_reason' => $this->blockedReason,
        ];
    }
}
