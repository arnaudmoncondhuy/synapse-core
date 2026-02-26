<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché après l'exécution réussie d'un outil individuel.
 *
 * Permet de monitorer le succès des appels d'outils ou d'effectuer des post-traitements
 * spécifiques à un outil donné (ex: logger des changements en base de données).
 */
class SynapseToolCallCompletedEvent extends Event
{
    /**
     * @param string $toolName     Nom de l'outil exécuté.
     * @param mixed  $result       Donnée retournée par l'outil.
     * @param array  $toolCallData Payload original de l'appel (ID, arguments).
     */
    public function __construct(
        private string $toolName,
        private mixed $result,
        private array $toolCallData = [],
    ) {}

    public function getToolName(): string
    {
        return $this->toolName;
    }

    /**
     * Retourne le résultat brut de l'exécution.
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * Retourne les données techniques de l'appel initial.
     */
    public function getToolCallData(): array
    {
        return $this->toolCallData;
    }
}
