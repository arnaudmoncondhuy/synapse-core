<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @deprecated Depuis le refactoring du pipeline de prompt (Mars 2026).
 *
 * Remplacé par 5 events de phase explicites dans ArnaudMoncondhuy\SynapseCore\Event\Prompt\ :
 *   - PromptBuildEvent    : construction du prompt de base (system + history + tools)
 *   - PromptEnrichEvent   : enrichissement (mémoire utilisateur, RAG)
 *   - PromptOptimizeEvent : troncation du contexte selon la context window
 *   - PromptFinalizeEvent : injection du master prompt
 *   - PromptCaptureEvent  : capture debug (lecture seule)
 *
 * Migration : écouter le(s) event(s) de phase correspondant(s) à votre usage.
 *
 * @example
 * ```php
 * // AVANT (déprécié)
 * #[AsEventListener(event: SynapsePrePromptEvent::class, priority: 40)]
 * public function onPrePrompt(SynapsePrePromptEvent $event): void { ... }
 *
 * // APRÈS
 * #[AsEventListener(event: PromptEnrichEvent::class)]
 * public function onEnrich(PromptEnrichEvent $event): void { ... }
 * ```
 */
class SynapsePrePromptEvent extends Event
{
    /** @var array<string, mixed> */
    private array $prompt;

    private ?SynapseRuntimeConfig $config;

    /** @var list<array{mime_type: string, data: string}> */
    private array $attachments = [];

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $prompt
     * @param list<array{mime_type: string, data: string}> $attachments
     */
    public function __construct(
        private string $message,
        private array $options,
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
    public function setPrompt(array $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    public function getConfig(): ?SynapseRuntimeConfig
    {
        return $this->config;
    }

    public function setConfig(SynapseRuntimeConfig $config): self
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
    public function setAttachments(array $attachments): self
    {
        $this->attachments = $attachments;

        return $this;
    }
}
