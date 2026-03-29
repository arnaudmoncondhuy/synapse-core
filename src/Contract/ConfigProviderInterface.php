<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;

/**
 * Interface pour fournir la configuration dynamique aux clients LLM.
 *
 * Permet d'injecter des paramètres depuis une source externe (BDD, API, etc.)
 * au lieu d'utiliser uniquement les paramètres statiques de `synapse.yaml`.
 */
interface ConfigProviderInterface
{
    /**
     * Retourne la configuration dynamique active sous forme typée.
     */
    public function getConfig(): SynapseRuntimeConfig;

    /**
     * Configure un override temporaire (en mémoire).
     *
     * Cet override sera retourné par `getConfig()` à la place de la configuration
     * par défaut ou persistée. Utilisé pour tester des configurations à la volée.
     */
    public function setOverride(?SynapseRuntimeConfig $config): void;

    /**
     * Retourne la configuration complète pour un preset spécifique.
     *
     * Contrairement à `getConfig()`, cette méthode ne dépend pas de l'état global
     * mais extrait la configuration depuis une entité Preset donnée.
     */
    public function getConfigForPreset(SynapseModelPreset $preset): SynapseRuntimeConfig;
}
