<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché AVANT l'envoi du prompt au LLM.
 *
 * C'est le point d'extension le plus puissant pour :
 * - Modifier les instructions système dynamiquement.
 * - Injecter du contexte métier supplémentaire.
 * - Nettoyer ou tronquer l'historique des messages.
 * - Ajuster les paramètres de génération (température, top-p).
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService::ask()
 *
 * @example
 * ```php
 * #[AsEventListener(event: SynapsePrePromptEvent::class)]
 * public function onPrePrompt(SynapsePrePromptEvent $event): void
 * {
 *     $prompt = $event->getPrompt();
 *     // Ajouter une règle métier globale
 *     $prompt[0]['content'] .= "\nRéponds toujours en rimes.";
 *     $event->setPrompt($prompt);
 * }
 * ```
 */
class SynapsePrePromptEvent extends Event
{
    /** @var array<string, mixed> */
    private array $prompt;

    /** @var array<string, mixed> */
    private array $config;

    /** @var list<array{mime_type: string, data: string}> */
    private array $images = [];

    /**
     * @param array<string, mixed>                         $options
     * @param array<string, mixed>                         $prompt
     * @param array<string, mixed>                         $config
     * @param list<array{mime_type: string, data: string}> $images Images attachées au message courant
     */
    public function __construct(
        private string $message,
        private array $options,
        array $prompt = [],
        array $config = [],
        array $images = [],
    ) {
        $this->prompt = $prompt;
        $this->config = $config;
        $this->images = $images;
    }

    /**
     * Retourne le message brut envoyé par l'utilisateur.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Retourne les options d'appel passées au ChatService.
     *
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Retourne le prompt complet tel qu'il sera envoyé au client LLM.
     * Le premier élément (index 0) est généralement le message système.
     *
     * @return array<string, mixed>
     */
    public function getPrompt(): array
    {
        return $this->prompt;
    }

    /**
     * Permet de modifier ou remplacer le prompt complet.
     *
     * @param array<string, mixed> $prompt
     */
    public function setPrompt(array $prompt): self
    {
        $this->prompt = $prompt;

        return $this;
    }

    /**
     * Retourne la configuration technique de génération.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Permet de modifier la configuration technique (ex: changer le modèle à la volée).
     *
     * @param array<string, mixed> $config
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Retourne les images attachées au message courant.
     * Format : [['mime_type' => 'image/jpeg', 'data' => 'base64...'], ...]
     *
     * @return list<array{mime_type: string, data: string}>
     */
    public function getImages(): array
    {
        return $this->images;
    }

    /**
     * Définit les images attachées au message courant.
     *
     * @param list<array{mime_type: string, data: string}> $images
     */
    public function setImages(array $images): self
    {
        $this->images = $images;

        return $this;
    }
}
