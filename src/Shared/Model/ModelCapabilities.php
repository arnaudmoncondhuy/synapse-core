<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Profil de capacités d'un modèle LLM.
 *
 * Décrit ce que supporte ou non un modèle donné.
 * Utilisé par les clients LLM pour adapter le payload à envoyer à l'API.
 */
class ModelCapabilities
{
    /**
     * @param int[] $dimensions
     */
    public function __construct(
        /** Identifiant exact du modèle (tel qu'envoyé à l'API) */
        public readonly string $model,

        /** Provider auquel appartient ce modèle */
        public readonly string $provider,

        /** Type du modèle (chat, embedding, etc) */
        public readonly string $type = 'chat',

        /** Dimensions proposées pour les embeddings */
        public readonly array $dimensions = [],

        /** Supporte le mode thinking étendu (budget de tokens de réflexion) */
        public readonly bool $supportsThinking = false,

        /** Supporte les filtres de sécurité (safetySettings Gemini) */
        public readonly bool $supportsSafetySettings = false,

        /** Supporte le paramètre topK */
        public readonly bool $supportsTopK = false,

        /** Supporte le function calling / tool use */
        public readonly bool $supportsFunctionCalling = true,

        /** Supporte le streaming SSE/NDJSON */
        public readonly bool $supportsStreaming = true,

        /** Supporte un system prompt / instruction système */
        public readonly bool $supportsSystemPrompt = true,

        /** Taille de la fenêtre de contexte maximale en tokens (legacy — utiliser max_input_tokens) */
        public readonly ?int $contextWindow = null,

        /** Prix par défaut par million de tokens (Input) */
        public readonly ?float $pricingInput = null,

        /** Prix par défaut par million de tokens (Output) */
        public readonly ?float $pricingOutput = null,

        // ── Phase 1 : Contexte asymétrique ───────────────────────────────────

        /** Max tokens en entrée — si null, fallback vers contextWindow */
        public readonly ?int $maxInputTokens = null,

        /** Max tokens en sortie — si null, pas de limite explicite connue */
        public readonly ?int $maxOutputTokens = null,

        // ── Phase 1 : Modalités ──────────────────────────────────────────────

        /** Supporte l'analyse d'images en entrée */
        public readonly bool $supportsVision = false,

        /** Supporte l'appel parallèle de plusieurs tools en une réponse */
        public readonly bool $supportsParallelToolCalls = false,

        /** Supporte le JSON Mode / Structured Outputs */
        public readonly bool $supportsResponseSchema = false,

        // ── Phase 1 : Lifecycle ──────────────────────────────────────────────

        /** Date de dépréciation du modèle au format YYYY-MM-DD, ou null */
        public readonly ?string $deprecatedAt = null,

        // ── Provider-specific ────────────────────────────────────────────────

        /** Région Vertex AI par défaut pour ce modèle (ex: 'global', 'europe-west1') */
        public readonly ?string $vertexRegion = null,
    ) {
    }

    /**
     * Retourne le nombre maximum de tokens en entrée.
     * Fallback : maxInputTokens → contextWindow → null.
     */
    public function getEffectiveMaxInputTokens(): ?int
    {
        return $this->maxInputTokens ?? $this->contextWindow;
    }

    /**
     * Indique si le modèle est déprécié à une date donnée (défaut : maintenant).
     */
    public function isDeprecated(?\DateTimeInterface $at = null): bool
    {
        if (null === $this->deprecatedAt) {
            return false;
        }
        $deprecation = \DateTimeImmutable::createFromFormat('Y-m-d', $this->deprecatedAt);
        if (!$deprecation) {
            return false;
        }

        return ($at ?? new \DateTimeImmutable()) >= $deprecation;
    }

    /**
     * Vérifie si le modèle supporte une capacité donnée.
     *
     * @param string $capability Capacité à vérifier (clé YAML sans le préfixe 'supports_', ex: 'thinking', 'vision')
     *
     * @return bool true si supportée, false sinon (y compris pour les capacités inconnues)
     */
    public function supports(string $capability): bool
    {
        return match ($capability) {
            'supports_thinking', 'thinking' => $this->supportsThinking,
            'supports_safety_settings', 'safety_settings' => $this->supportsSafetySettings,
            'supports_top_k', 'top_k' => $this->supportsTopK,
            'supports_function_calling', 'function_calling' => $this->supportsFunctionCalling,
            'supports_streaming', 'streaming' => $this->supportsStreaming,
            'supports_system_prompt', 'system_prompt' => $this->supportsSystemPrompt,
            'supports_vision', 'vision' => $this->supportsVision,
            'supports_parallel_tool_calls', 'parallel_tool_calls' => $this->supportsParallelToolCalls,
            'supports_response_schema', 'response_schema' => $this->supportsResponseSchema,
            default => false,
        };
    }
}
