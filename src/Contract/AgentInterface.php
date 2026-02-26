<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Contrat pour un agent IA orchestrateur.
 *
 * Un agent implémente une tâche complexe impliquant potentiellement plusieurs appels LLM,
 * des boucles de raisonnement, ou l'orchestration de sous-systèmes.
 *
 * À la différence d'un `AiToolInterface` (qui est une fonction simple appelée par le LLM),
 * un Agent peut être appelé directement par l'application pour accomplir un objectif de haut niveau.
 *
 * Exemples d'usages :
 * - Analyse multi-documents complexe.
 * - Validation d'un preset par simulation.
 * - Génération de rapports structurés après plusieurs étapes de réflexion.
 */
interface AgentInterface
{
    /**
     * Identifiant unique de l'agent (recommandé : snake_case).
     *
     * @example 'preset_validator', 'document_summarizer'
     */
    public function getName(): string;

    /**
     * Description en langage naturel de la mission de l'agent.
     *
     * Utilisée pour l'affichage dans l'administration et pour aider à l'auto-documentation
     * de l'écosystème IA.
     */
    public function getDescription(): string;

    /**
     * Exécute la logique de l'agent avec les paramètres fournis.
     *
     * @param array<string, mixed> $input Paramètres d'entrée spécifiques à la mission de l'agent.
     *
     * @return array<string, mixed> Résultat structuré de l'exécution de l'agent.
     */
    public function run(array $input): array;
}
