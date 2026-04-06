<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event\Prompt;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Classe de base partagée par tous les events de phase du pipeline de prompt.
 *
 * Chaque phase reçoit et peut modifier le même contexte : message, options, prompt, config, attachments.
 */
abstract class AbstractPromptEvent extends Event
{
    /** @var array<string, mixed> */
    private array $prompt;

    private ?SynapseRuntimeConfig $config;

    /** @var list<array{mime_type: string, data: string}> */
    private array $attachments;

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $prompt
     * @param list<array{mime_type: string, data: string}> $attachments
     */
    public function __construct(
        private readonly string $message,
        private readonly array $options,
        array $prompt = [],
        ?SynapseRuntimeConfig $config = null,
        array $attachments = [],
    ) {
        $this->prompt = $prompt;
        $this->config = $config;
        $this->attachments = $attachments;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPrompt(): array
    {
        return $this->prompt;
    }

    /**
     * @param array<string, mixed> $prompt
     */
    public function setPrompt(array $prompt): static
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getConfig(): ?SynapseRuntimeConfig
    {
        return $this->config;
    }

    public function setConfig(SynapseRuntimeConfig $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return list<array{mime_type: string, data: string}>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @param list<array{mime_type: string, data: string}> $attachments
     */
    public function setAttachments(array $attachments): static
    {
        $this->attachments = $attachments;

        return $this;
    }
}
