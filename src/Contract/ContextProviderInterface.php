<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour l'injection de contexte dynamique au modèle IA.
 *
 * Implémentez cette interface dans votre application pour personnaliser
 * les instructions système et injecter des données contextuelles (identité,
 * date, préférences utilisateur) au début de chaque échange.
 *
 * @example
 * ```php
 * class AppContextProvider implements ContextProviderInterface {
 *     public function getSystemPrompt(): string {
 *         return "Tu es l'assistant de l'application MaSociété.";
 *     }
 *     public function getInitialContext(): array {
 *         return ['version' => '1.2', 'env' => 'prod'];
 *     }
 * }
 * ```
 */
interface ContextProviderInterface
{
    /**
     * Retourne le prompt système principal (identité et règles de base).
     *
     * Définit le comportement global de l'IA. Cette instruction est placée
     * en tête de chaque conversation.
     *
     * @return string Les instructions système pour le LLM.
     */
    public function getSystemPrompt(): string;

    /**
     * Retourne des données contextuelles additionnelles.
     *
     * Ces données sont converties en texte et injectées après le prompt système
     * pour donner au modèle des informations sur l'environnement actuel.
     *
     * @return array<string, mixed> Tableau clé-valeur de métadonnées.
     */
    public function getInitialContext(): array;
}
