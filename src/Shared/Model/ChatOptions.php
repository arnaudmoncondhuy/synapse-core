<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;

/**
 * Value Object pour les options passées à ChatService::ask().
 *
 * Remplace le array<string, mixed> $options.
 */
final class ChatOptions
{
    public function __construct(
        public readonly ?string $tone = null,
        public readonly ?string $agent = null,
        /** @var array<int, array<string, mixed>>|null */
        public readonly ?array $history = null,
        public readonly bool $stateless = false,
        public readonly ?bool $debug = null,
        public readonly ?bool $streaming = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $userId = null,
        public readonly ?float $estimatedCostReference = null,
        public readonly bool $resetConversation = false,
        public readonly ?string $systemPromptOverride = null,
        /** @var array<string>|string|null */
        public readonly array|string|null $tools = null,
        /** @var list<string>|null */
        public readonly ?array $toolsOverride = null,
        public readonly ?string $modelPreset = null,
        public readonly ?SynapseModelPreset $preset = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $preset = $data['preset'] ?? null;

        return new self(
            tone: $data['tone'] ?? null,
            agent: $data['agent'] ?? null,
            history: $data['history'] ?? null,
            stateless: (bool) ($data['stateless'] ?? false),
            debug: isset($data['debug']) ? (bool) $data['debug'] : null,
            streaming: isset($data['streaming']) ? (bool) $data['streaming'] : null,
            conversationId: $data['conversation_id'] ?? null,
            userId: $data['user_id'] ?? null,
            estimatedCostReference: isset($data['estimated_cost_reference']) ? (float) $data['estimated_cost_reference'] : null,
            resetConversation: (bool) ($data['reset_conversation'] ?? false),
            systemPromptOverride: $data['system_prompt'] ?? null,
            tools: $data['tools'] ?? null,
            toolsOverride: $data['tools_override'] ?? null,
            modelPreset: $data['model_preset'] ?? null,
            preset: $preset instanceof SynapseModelPreset ? $preset : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tone' => $this->tone,
            'agent' => $this->agent,
            'history' => $this->history,
            'stateless' => $this->stateless,
            'debug' => $this->debug,
            'streaming' => $this->streaming,
            'conversation_id' => $this->conversationId,
            'user_id' => $this->userId,
            'estimated_cost_reference' => $this->estimatedCostReference,
            'reset_conversation' => $this->resetConversation,
            'system_prompt' => $this->systemPromptOverride,
            'tools' => $this->tools,
            'tools_override' => $this->toolsOverride,
            'model_preset' => $this->modelPreset,
            'preset' => $this->preset,
        ];
    }
}
