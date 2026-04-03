<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Contrat pour tout client LLM intégré dans Synapse.
 *
 * Cette interface permet de brancher n'importe quel provider LLM
 * sans modifier le `ChatService`. Chaque client est responsable de la communication avec l'API
 * distante et de la normalisation du format.
 *
 * ═══════════════════════════════════════════════════════
 * FORMAT DE L'HISTORIQUE (OpenAI canonical)
 * ═══════════════════════════════════════════════════════
 *
 * L'entrée `$contents` respecte le format standard OpenAI :
 *
 * ```php
 * [
 *     ['role' => 'system',    'content' => 'Instructions du système...'],
 *     ['role' => 'user',      'content' => 'Question utilisateur'],
 *     ['role' => 'assistant', 'content' => 'Réponse...', 'tool_calls' => [...]],
 *     ['role' => 'tool',      'tool_call_id' => '...', 'content' => 'Résultat'],
 * ]
 * ```
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService
 */
interface LlmClientInterface
{
    /**
     * Identifiant interne du fournisseur.
     *
     * Doit être en minuscule et sans espace (ex : 'my_provider').
     * Cet identifiant est utilisé dans la configuration YAML et en base de données.
     */
    public function getProviderName(): string;

    /**
     * Génère du contenu en mode streaming (Server-Sent Events).
     *
     * @param array<int, array<string, mixed>> $contents Historique complet (format OpenAI canonical)
     * @param array<int, array<string, mixed>> $tools Déclarations des outils disponibles au format JSON Schema
     * @param string|null $model Identifiant du modèle à utiliser
     * @param array<string, mixed> $debugOut Sortie de debug (passage par référence)
     *
     * @return \Generator<int, array<string, mixed>> yield des chunks normalisés contenant 'text', 'usage', etc
     */
    public function streamGenerateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array &$debugOut = [],
    ): \Generator;

    /**
     * Génère du contenu en mode synchrone (bloquant).
     *
     * @param array<int, array<string, mixed>> $contents Historique complet
     * @param array<int, array<string, mixed>> $tools Déclarations des outils
     * @param string|null $model Modèle à utiliser
     * @param array<string, mixed> $options Options additionnelles (température, top-p, etc.)
     * @param array<string, mixed> $debugOut Sortie de debug
     *
     * @return array<string, mixed> Le dernier chunk normalisé de la réponse
     */
    public function generateContent(
        array $contents,
        array $tools = [],
        ?string $model = null,
        array $options = [],
        array &$debugOut = [],
    ): array;

    /**
     * Retourne la définition des champs de configuration pour l'administration.
     *
     * Permet de générer dynamiquement le formulaire de saisie des credentials dans l'admin.
     * Chaque champ peut définir son label, type (text, password, select), et son caractère obligatoire.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool}>
     */
    public function getCredentialFields(): array;

    /**
     * Valide l'intégrité des credentials fournis.
     *
     * @param array<string, mixed> $credentials Les valeurs saisies dans l'admin
     *
     * @throws \InvalidArgumentException Si les formats sont incorrects
     * @throws \Exception Si la validation échoue (ex: test de connexion impossible)
     */
    public function validateCredentials(array $credentials): void;

    /**
     * Nom d'affichage lisible du fournisseur (ex: 'My Provider').
     */
    public function getDefaultLabel(): string;

    /**
     * Icône Lucide du provider pour l'interface admin (ex: 'zap', 'cloud', 'server').
     */
    public function getIcon(): string;

    /**
     * Devise par défaut des tarifs de ce provider (code ISO 4217, ex: 'USD', 'EUR').
     */
    public function getDefaultCurrency(): string;

    /**
     * Schéma des options spécifiques au provider pour le formulaire de preset admin.
     *
     * Retourne un tableau de définitions de champs exploitées par le JS du formulaire.
     *
     * @return array{fields: list<array<string, mixed>>}
     */
    public function getProviderOptionsSchema(): array;

    /**
     * Valide et nettoie les options spécifiques au provider d'un preset.
     *
     * @param array<string, mixed> $options Options saisies par l'utilisateur
     * @param \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities $caps Capacités du modèle sélectionné
     *
     * @return array<string, mixed> Options nettoyées
     */
    public function validateProviderOptions(array $options, \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities $caps): array;
}
