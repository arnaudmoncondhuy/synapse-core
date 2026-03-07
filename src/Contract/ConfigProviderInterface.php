<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;

/**
 * Interface pour fournir la configuration dynamique aux clients LLM.
 *
 * Permet d'injecter des paramètres depuis une source externe (BDD, API, etc.)
 * au lieu d'utiliser uniquement les paramètres statiques de `synapse.yaml`.
 * Cette interface est utilisée par le bundle pour récupérer les réglages de
 * sécurité et les paramètres de génération.
 */
interface ConfigProviderInterface
{
    /**
     * Retourne la configuration dynamique active.
     *
     * @return array<string, mixed> Configuration structurée pour le client LLM
     */
    public function getConfig(): array;

    /**
     * Configure un override temporaire (en mémoire).
     *
     * Cet override sera retourné par `getConfig()` à la place de la configuration
     * par défaut ou persistée. Utilisé pour tester des configurations à la volée.
     *
     * @param array<string, mixed>|null $config la configuration d'override ou null pour la désactiver
     */
    public function setOverride(?array $config): void;

    /**
     * Retourne la configuration complète pour un preset spécifique.
     *
     * Contrairement à `getConfig()`, cette méthode ne dépend pas de l'état global
     * mais extrait la configuration depuis une entité Preset donnée.
     *
     * @param SynapseModelPreset $preset L'entité preset à analyser
     *
     * @return array<string, mixed> configuration extraite du preset
     */
    public function getConfigForPreset(SynapseModelPreset $preset): array;
}
