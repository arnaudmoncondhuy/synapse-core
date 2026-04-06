<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registre centralisé des agents "code" : classes PHP qui implémentent
 * directement {@see AgentInterface} et sont découvertes via le tag DI
 * `synapse.agent` (auto-configuré par {@see \ArnaudMoncondhuy\SynapseCore\DependencyInjection\SynapseCoreExtension}).
 *
 * Même pattern que {@see \ArnaudMoncondhuy\SynapseCore\Engine\ToolRegistry} : les services
 * taggés sont collectés via {@see AutowireIterator}, indexés par {@see AgentInterface::getName()},
 * puis exposés à {@see AgentResolver}.
 *
 * Les agents "config" (entité {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent})
 * ne passent **pas** par ce registre : ils sont résolus par {@see AgentResolver} via
 * l'{@see \ArnaudMoncondhuy\SynapseCore\AgentRegistry} historique et enveloppés dans un
 * {@see ConfiguredAgent} à la volée.
 *
 * ## Collision de clés
 *
 * Deux agents code déclarant le même `getName()` provoquent une exception fatale
 * au boot : c'est une erreur de programmation qu'il faut corriger immédiatement.
 * Les collisions entre un agent code et un agent BDD sont gérées plus haut, dans
 * {@see AgentResolver} (code gagne, warning loggé).
 */
final class CodeAgentRegistry
{
    /** @var array<string, AgentInterface> */
    private array $agents = [];

    /**
     * @param iterable<AgentInterface> $agents
     */
    public function __construct(
        #[AutowireIterator('synapse.agent')]
        iterable $agents,
    ) {
        foreach ($agents as $agent) {
            $name = $agent->getName();
            if (isset($this->agents[$name])) {
                throw new \LogicException(sprintf('Duplicate code agent name "%s": %s and %s both declare this name. Agent names must be unique across all classes implementing AgentInterface.', $name, $this->agents[$name]::class, $agent::class));
            }
            $this->agents[$name] = $agent;
        }
    }

    /**
     * Récupère un agent code par son nom, ou null s'il n'existe pas.
     */
    public function get(string $name): ?AgentInterface
    {
        return $this->agents[$name] ?? null;
    }

    /**
     * Vérifie la présence d'un agent code par son nom.
     */
    public function has(string $name): bool
    {
        return isset($this->agents[$name]);
    }

    /**
     * Retourne tous les agents code enregistrés, indexés par nom.
     *
     * @return array<string, AgentInterface>
     */
    public function all(): array
    {
        return $this->agents;
    }

    /**
     * Retourne la liste des noms d'agents code enregistrés.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->agents);
    }
}
