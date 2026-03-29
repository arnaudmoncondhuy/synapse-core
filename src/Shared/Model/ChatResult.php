<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object représentant le résultat final d'un appel ChatService::ask().
 */
final readonly class ChatResult
{
    public function __construct(
        public string $answer,
        public ?string $debugId,
        public TokenUsage $usage,
        /** @var array<int, array<string, mixed>> */
        public array $safetyRatings,
        public string $model,
        public ?int $presetId,
        public ?int $agentId,
    ) {
    }

    /**
     * @return array{answer: string, debug_id: ?string, usage: array<string, int>, safety: array<int, array<string, mixed>>, model: string, preset_id: ?int, agent_id: ?int}
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'debug_id' => $this->debugId,
            'usage' => $this->usage->toArray(),
            'safety' => $this->safetyRatings,
            'model' => $this->model,
            'preset_id' => $this->presetId,
            'agent_id' => $this->agentId,
        ];
    }
}
