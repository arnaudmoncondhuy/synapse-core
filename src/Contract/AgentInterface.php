<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Agent\Output;

/**
 * Contrat pour un agent IA orchestrateur.
 *
 * Un agent implémente une tâche impliquant potentiellement plusieurs appels LLM,
 * des boucles de raisonnement, ou l'orchestration de sous-systèmes.
 *
 * À la différence d'un `AiToolInterface` (qui est une fonction simple appelée par le LLM),
 * un Agent peut être appelé directement par l'application pour accomplir un objectif de haut niveau.
 *
 * ## Alignement terminologique avec `symfony/ai`
 *
 * Les noms `call()`, `Input` et `Output` sont volontairement alignés sur
 * `Symfony\AI\Agent\AgentInterface`. C'est un alignement de **vocabulaire**,
 * pas un chemin de migration : `symfony/ai` est encore en développement et
 * aucune adoption n'est prévue à court ou moyen terme. L'alignement sert
 * uniquement à ne pas construire une "deuxième réalité" qui serait douloureuse
 * à rapprocher plus tard si le jour vient.
 *
 * ## Exemples d'usages
 *
 * - Analyse multi-documents complexe
 * - Validation d'un preset par simulation
 * - Génération de rapports structurés après plusieurs étapes de réflexion
 * - Orchestration de sous-agents (via {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver})
 *
 * ## Contexte runtime
 *
 * Le contexte d'exécution (traçabilité, profondeur, budget) est transporté via
 * `$options['context']` sous forme de {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}.
 * S'il est absent, un contexte racine est créé automatiquement par le resolver.
 */
interface AgentInterface
{
    /**
     * Identifiant unique de l'agent (snake_case recommandé).
     *
     * Exemples : 'preset_validator', 'document_summarizer', 'bulletin_analyzer'.
     */
    public function getName(): string;

    /**
     * Libellé court lisible par un humain (titre de la card admin, etc.).
     *
     * Différent de `getName()` (clé technique snake_case) : ce champ est affiché
     * dans l'interface d'administration et peut contenir des espaces, majuscules,
     * caractères accentués, etc.
     *
     * {@see \ArnaudMoncondhuy\SynapseCore\Agent\AbstractAgent} fournit une implémentation
     * par défaut qui convertit le nom snake_case en Title Case.
     * Surchargez cette méthode pour un libellé plus précis (ex : "Validateur de preset LLM").
     */
    public function getLabel(): string;

    /**
     * Description en langage naturel de l'agent.
     *
     * Utilisée pour l'affichage dans l'administration et pour aider à l'auto-documentation
     * de l'écosystème IA. Cette méthode est un ajout Synapse par rapport à `symfony/ai`.
     */
    public function getDescription(): string;

    /**
     * Exécute l'agent.
     *
     * Nom de méthode aligné sur `Symfony\AI\Agent\AgentInterface::call()`.
     *
     * @param Input $input Entrée métier (message, pièces jointes, données structurées)
     * @param array<string, mixed> $options Options runtime. Peut contenir une clé `'context'` portant un
     *                                      {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}. Les autres
     *                                      clés sont spécifiques à chaque implémentation (ex: `preset`, `debug`).
     *
     * @return Output Résultat structuré de l'exécution de l'agent
     */
    public function call(Input $input, array $options = []): Output;
}
