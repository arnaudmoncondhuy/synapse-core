<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour les Outils IA (Function Calling / Tool Use).
 *
 * Implémentez cette interface dans votre application pour créer des outils personnalisés
 * que le modèle IA pourra appeler dynamiquement au cours d'une conversation.
 * Ces outils permettent au LLM d'interagir avec le système externe (ex: base de données, API, calculs).
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Core\Chat\ToolRegistry
 *
 * @example
 * ```php
 * class WeatherTool implements AiToolInterface {
 *     public function getName(): string { return 'get_weather'; }
 *     public function getDescription(): string { return 'Retourne la météo d\'une ville.'; }
 *     public function getInputSchema(): array {
 *         return [
 *             'type' => 'object',
 *             'properties' => ['city' => ['type' => 'string']]
 *         ];
 *     }
 *     public function execute(array $parameters): mixed {
 *         return "Il fait beau à " . $parameters['city'];
 *     }
 * }
 * ```
 */
interface AiToolInterface
{
    /**
     * Retourne le nom unique de l'outil (utilisé par le modèle pour l'appel).
     *
     * Ce nom doit être explicite et unique (ex: "get_current_weather", "search_database").
     * Il est recommandé d'utiliser du snake_case.
     *
     * @return string le nom technique de la fonction
     */
    public function getName(): string;

    /**
     * Retourne une description de la fonction de l'outil.
     *
     * Cette description est **CRITIQUE** : elle aide le modèle à décider QUAND utiliser cet outil.
     * Soyez précis sur ce que l'outil fait et ne fait pas.
     *
     * @return string description en langage naturel pour le LLM
     */
    public function getDescription(): string;

    /**
     * Retourne le schéma JSON des paramètres d'entrée acceptés par l'outil.
     *
     * Doit respecter le formalisme OpenAPI / JSON Schema.
     * Les types supportés sont généralement : string, number, integer, boolean, object, array.
     *
     * @return array{type: string, properties: array<string, mixed>, required?: string[]} structure du schéma
     */
    public function getInputSchema(): array;

    /**
     * Exécute la logique métier de l'outil avec les paramètres fournis par le modèle.
     *
     * @param array<string, mixed> $parameters les paramètres extraits et validés par le modèle
     *
     * @return mixed le résultat de l'exécution (SERA SÉRIALISÉ en JSON pour être renvoyé au modèle)
     */
    public function execute(array $parameters): mixed;
}
