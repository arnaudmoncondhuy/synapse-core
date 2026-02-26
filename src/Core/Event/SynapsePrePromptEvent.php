<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

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
 * @see \ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService::ask()
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
    private array $prompt;
    private array $config;

    /**
     * @param string               $message Le message initial de l'utilisateur
     * @param array<string, mixed> $options Les options passées au ChatService
     * @param array<int, array>    $prompt  L'historique complet (incluant le message système)
     * @param array<string, mixed> $config  La configuration de génération (model, temperature, etc.)
     */
    public function __construct(
        private string $message,
        private array $options,
        array $prompt = [],
        array $config = [],
    ) {
        $this->prompt = $prompt;
        $this->config = $config;
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
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Retourne le prompt complet tel qu'il sera envoyé au client LLM.
     * Le premier élément (index 0) est généralement le message système.
     *
     * @return array<int, array{role: string, content: ?string, tool_calls?: array}>
     */
    public function getPrompt(): array
    {
        return $this->prompt;
    }

    /**
     * Permet de modifier ou remplacer le prompt complet.
     */
    public function setPrompt(array $prompt): self
    {
        $this->prompt = $prompt;
        return $this;
    }

    /**
     * Retourne la configuration technique de génération.
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Permet de modifier la configuration technique (ex: changer le modèle à la volée).
     */
    public function setConfig(array $config): self
    {
        $this->config = $config;
        return $this;
    }
}
