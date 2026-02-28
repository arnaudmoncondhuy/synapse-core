<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Déclenché après chaque enregistrement d'usage (tokens / coût).
 *
 * Permet alertes, webhooks, analytics externes.
 */
class SynapseUsageRecordedEvent extends Event
{
    public function __construct(
        private string $module,
        private string $action,
        private string $model,
        private int $promptTokens,
        private int $completionTokens,
        private int $thinkingTokens,
        private float $costInReferenceCurrency,
        private ?string $userId = null,
        private ?string $conversationId = null,
        private ?int $presetId = null,
    ) {}

    public function getModule(): string
    {
        return $this->module;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getThinkingTokens(): int
    {
        return $this->thinkingTokens;
    }

    public function getCostInReferenceCurrency(): float
    {
        return $this->costInReferenceCurrency;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function getPresetId(): ?int
    {
        return $this->presetId;
    }
}
