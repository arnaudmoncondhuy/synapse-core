<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;

/**
 * Wrapper d'une entité {@see SynapseAgent} exposée derrière le contrat unifié {@see AgentInterface}.
 *
 * Un `ConfiguredAgent` représente un agent **configuré en base** (système prompt, preset,
 * ton, outils autorisés) et délègue son exécution au pipeline existant de
 * {@see ChatService::ask()}. Aucun nouveau chemin d'exécution n'est créé : on se contente
 * de façonner les options au bon format et de convertir le tableau de retour en {@see Output}.
 *
 * Contrairement aux agents code (classes écrites par l'hôte ou le bundle qui implémentent
 * directement {@see AgentInterface}), cette classe **n'est pas un service DI singleton** :
 * une instance est créée à la volée par {@see AgentResolver} pour chaque entité résolue.
 */
final class ConfiguredAgent implements AgentInterface
{
    public function __construct(
        private readonly SynapseAgent $entity,
        private readonly ChatService $chatService,
    ) {
    }

    public function getName(): string
    {
        return $this->entity->getKey();
    }

    public function getDescription(): string
    {
        return $this->entity->getDescription();
    }

    public function call(Input $input, array $options = []): Output
    {
        // Forcer la clé d'agent : l'entité wrappée est celle qui compte.
        $options['agent'] = $this->entity->getKey();

        // Module/action pour le token accounting (synapse_llm_call).
        // Action générique — l'agent spécifique est déjà traçé via agent_id.
        $options['module'] = $options['module'] ?? 'agent';
        $options['action'] = $options['action'] ?? 'agent_call';

        // Activer le debug par défaut pour la traçabilité des appels programmatiques.
        // L'appelant peut surcharger via $options['debug'] = false.
        $options['debug'] = $options['debug'] ?? true;

        // Si le message est vide mais qu'un input structuré est fourni (workflow step),
        // construire un message textuel à partir des données structurées.
        $message = $input->getMessage();
        if ('' === $message && [] !== $input->getStructured()) {
            $message = $this->buildMessageFromStructured($input->getStructured());
        }

        $result = $this->chatService->ask(
            $message,
            $options,
            $input->getAttachments(),
        );

        return Output::fromChatServiceResult($result);
    }

    /**
     * Expose l'entité sous-jacente — utile pour les callers qui ont besoin
     * des métadonnées non exposées par le contrat (preset, tools, accessControl, ...).
     */
    public function getEntity(): SynapseAgent
    {
        return $this->entity;
    }

    /**
     * Convertit un input structuré (venant d'un workflow step) en message texte
     * exploitable par ChatService. Si une seule valeur scalaire, l'utilise directement ;
     * sinon sérialise en JSON pour que l'agent reçoive toutes les données.
     *
     * @param array<string, mixed> $structured
     */
    private function buildMessageFromStructured(array $structured): string
    {
        $values = array_values($structured);
        if (1 === count($values) && is_string($values[0])) {
            return $values[0];
        }

        return json_encode($structured, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
