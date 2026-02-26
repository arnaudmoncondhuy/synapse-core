<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Événement déclenché lorsque le LLM demande l'exécution d'un ou plusieurs outils.
 *
 * C'est le cœur du "Function Calling". Synapse décompose la demande du modèle
 * et émet cet événement. Les écouteurs (subscribers) sont chargés d'exécuter
 * la logique métier et de renvoyer le résultat via `setToolResult()`.
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Core\Event\ToolExecutionSubscriber
 */
class SynapseToolCallRequestedEvent extends Event
{
    /** @var array<array{id: string, name: string, args: array}> */
    private array $toolCalls;
    private array $results = [];

    /**
     * @param array<array{id: string, name: string, args: array}> $toolCalls Liste des appels d'outils demandés par le LLM.
     */
    public function __construct(array $toolCalls)
    {
        $this->toolCalls = $toolCalls;
    }

    /**
     * Retourne la liste des outils que le modèle souhaite appeler.
     *
     * @return array<array{id: string, name: string, args: array}>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * Enregistre le résultat de l'exécution d'un outil.
     *
     * @param string $toolName Nom technique de l'outil.
     * @param mixed  $result   Donnée renvoyée par l'application (sera JSON-sérialisée pour le LLM).
     */
    public function setToolResult(string $toolName, mixed $result): self
    {
        $this->results[$toolName] = $result;
        return $this;
    }

    /**
     * Retourne l'ensemble des résultats collectés.
     *
     * @return array<string, mixed>
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Vérifie si tous les outils demandés ont reçu une réponse de l'application.
     */
    public function areAllResultsRegistered(): bool
    {
        return count($this->results) === count($this->toolCalls);
    }
}
