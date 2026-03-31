<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface optionnelle pour les outils qui veulent afficher un message personnalisé
 * dans l'indicateur de chargement pendant leur exécution.
 *
 * Si un outil n'implémente pas cette interface, le message par défaut
 * "Exécution de l'outil: {name}..." est affiché.
 *
 * @example
 * ```php
 * class SearchTool implements AiToolInterface, StatusAwareToolInterface {
 *     public function getExecutingMessage(): string {
 *         return 'Recherche dans la base de données...';
 *     }
 * }
 * ```
 */
interface StatusAwareToolInterface
{
    /**
     * Retourne le message à afficher dans l'interface pendant l'exécution de l'outil.
     */
    public function getExecutingMessage(): string;
}
