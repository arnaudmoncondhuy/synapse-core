<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;

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
     * @return array{
     *     safety_settings: array{
     *         enabled: bool,
     *         default_threshold: string,
     *         thresholds: array<string, string>
     *     },
     *     generation_config: array{
     *         temperature: float,
     *         top_p: float,
     *         top_k: int,
     *         max_output_tokens: ?int,
     *         stop_sequences: array<string>
     *     }
     * } Configuration structurée pour le client LLM.
     */
    public function getConfig(): array;

    /**
     * Configure un override temporaire (en mémoire).
     *
     * Cet override sera retourné par `getConfig()` à la place de la configuration
     * par défaut ou persistée. Utilisé pour tester des configurations à la volée.
     *
     * @param array|null $config La configuration d'override ou null pour la désactiver.
     */
    public function setOverride(?array $config): void;

    /**
     * Retourne la configuration complète pour un preset spécifique.
     *
     * Contrairement à `getConfig()`, cette méthode ne dépend pas de l'état global
     * mais extrait la configuration depuis une entité Preset donnée.
     *
     * @param SynapsePreset $preset L'entité preset à analyser.
     *
     * @return array Configuration extraite du preset.
     */
    public function getConfigForPreset(SynapsePreset $preset): array;
}
