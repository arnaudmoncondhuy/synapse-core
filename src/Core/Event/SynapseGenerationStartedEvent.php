<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché au TOUT DÉBUT du processus de génération.
 *
 * Cet événement survient avant toute analyse de contexte ou appel API. Il est utile
 * pour initialiser des compteurs, logger l'intention de l'utilisateur ou préparer
 * des ressources spécifiques.
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService::ask()
 */
class SynapseGenerationStartedEvent extends Event
{
    /**
     * @param string               $message Le message brut envoyé par l'utilisateur.
     * @param array<string, mixed> $options Les options de configuration passées à l'appel.
     */
    public function __construct(
        private string $message,
        private array $options = [],
    ) {}

    /**
     * Retourne le message utilisateur initial.
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Retourne les options de l'échange.
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
