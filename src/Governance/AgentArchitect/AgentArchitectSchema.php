<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\AgentArchitect;

/**
 * Schémas JSON Mode pour les différentes actions de l'{@see AgentArchitect}.
 *
 * Chaque méthode retourne un tableau `response_format` au format canonique
 * attendu par {@see \ArnaudMoncondhuy\SynapseCore\Engine\ResponseFormatNormalizer}
 * (OpenAI structured outputs). Les schémas définissent le contrat entre le LLM
 * et le {@see AgentArchitectProcessor} qui applique les propositions.
 *
 * @internal utilitaire statique, pas un service DI
 */
final class AgentArchitectSchema
{
    /**
     * Schéma pour la création d'un nouvel agent.
     *
     * @return array<string, mixed>
     */
    public static function createAgent(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'architect_create_agent',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'description' => 'Clé unique slug (a-z0-9_) pour identifier l\'agent.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Nom lisible de l\'agent.',
                        ],
                        'emoji' => [
                            'type' => 'string',
                            'description' => 'Un seul emoji illustrant l\'agent.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Description courte de l\'objectif de l\'agent (1-2 phrases).',
                        ],
                        'system_prompt' => [
                            'type' => 'string',
                            'description' => 'System prompt complet de l\'agent, structuré et précis.',
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Explication des choix de conception du prompt.',
                        ],
                    ],
                    'required' => ['key', 'name', 'emoji', 'description', 'system_prompt', 'reasoning'],
                ],
                'strict' => true,
            ],
        ];
    }

    /**
     * Schéma pour l'amélioration du prompt d'un agent existant.
     *
     * @return array<string, mixed>
     */
    public static function improvePrompt(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'architect_improve_prompt',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'new_system_prompt' => [
                            'type' => 'string',
                            'description' => 'Le nouveau system prompt complet (remplacement intégral, pas un diff).',
                        ],
                        'changes_summary' => [
                            'type' => 'string',
                            'description' => 'Résumé concis des modifications apportées (2-5 phrases).',
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Justification détaillée de chaque changement.',
                        ],
                    ],
                    'required' => ['new_system_prompt', 'changes_summary', 'reasoning'],
                ],
                'strict' => true,
            ],
        ];
    }

    /**
     * Schéma pour la création d'un nouveau workflow.
     *
     * Le champ `definition` suit le format pivot de {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow}.
     *
     * @return array<string, mixed>
     */
    public static function createWorkflow(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'architect_create_workflow',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => [
                            'type' => 'string',
                            'description' => 'Clé unique slug (a-z0-9_-) pour identifier le workflow.',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Nom lisible du workflow.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Description de ce que fait le workflow.',
                        ],
                        'steps' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'name' => [
                                        'type' => 'string',
                                        'description' => 'Identifiant unique de l\'étape dans ce workflow.',
                                    ],
                                    'agent_name' => [
                                        'type' => 'string',
                                        'description' => 'Clé de l\'agent qui exécute cette étape.',
                                    ],
                                    'input_mapping' => [
                                        'type' => 'object',
                                        'description' => 'Mapping des inputs — clé: nom du paramètre, valeur: expression JSONPath-lite ($.inputs.X ou $.steps.STEP.output.X).',
                                    ],
                                    'output_key' => [
                                        'type' => 'string',
                                        'description' => 'Clé sous laquelle stocker le résultat de cette étape (défaut: name).',
                                    ],
                                ],
                                'required' => ['name', 'agent_name'],
                            ],
                            'description' => 'Liste ordonnée des étapes du workflow.',
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => 'Justification de l\'architecture du workflow.',
                        ],
                    ],
                    'required' => ['key', 'name', 'description', 'steps', 'reasoning'],
                ],
                'strict' => true,
            ],
        ];
    }

    /**
     * Retourne le schéma correspondant à l'action demandée.
     *
     * @throws \InvalidArgumentException si l'action est inconnue
     *
     * @return array<string, mixed>
     */
    public static function forAction(string $action): array
    {
        return match ($action) {
            'create_agent' => self::createAgent(),
            'improve_prompt' => self::improvePrompt(),
            'create_workflow' => self::createWorkflow(),
            default => throw new \InvalidArgumentException(sprintf('Action architecte inconnue : "%s". Actions valides : create_agent, improve_prompt, create_workflow.', $action)),
        };
    }
}
