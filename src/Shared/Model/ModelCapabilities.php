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
        public readonly bool $thinking = false,

        /** Supporte les filtres de sécurité (safetySettings Gemini) */
        public readonly bool $safetySettings = false,

        /** Supporte le paramètre topK */
        public readonly bool $topK = false,

        /** Supporte le function calling / tool use */
        public readonly bool $functionCalling = true,

        /** Supporte le streaming SSE/NDJSON */
        public readonly bool $streaming = true,

        /** Supporte un system prompt / instruction système */
        public readonly bool $systemPrompt = true,

        /** Taille de la fenêtre de contexte maximale en tokens */
        public readonly ?int $contextWindow = null,

        /** Prix par défaut par million de tokens (Input) */
        public readonly ?float $pricingInput = null,

        /** Prix par défaut par million de tokens (Output) */
        public readonly ?float $pricingOutput = null,

        /** ID technique optionnel (ex: ID MaaS pour Vertex) */
        public readonly ?string $modelId = null,

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
     * @param string $capability Capacité à vérifier (valeurs acceptées : 'thinking', 'safety_settings', 'top_k',
     *                           'function_calling', 'streaming', 'system_prompt', 'vision',
     *                           'parallel_tool_calls', 'response_schema')
     *
     * @return bool true si supportée, false sinon (y compris pour les capacités inconnues)
     */
    public function supports(string $capability): bool
    {
        return match ($capability) {
            'thinking' => $this->thinking,
            'safety_settings' => $this->safetySettings,
            'top_k' => $this->topK,
            'function_calling' => $this->functionCalling,
            'streaming' => $this->streaming,
            'system_prompt' => $this->systemPrompt,
            'vision' => $this->supportsVision,
            'parallel_tool_calls' => $this->supportsParallelToolCalls,
            'response_schema' => $this->supportsResponseSchema,
            default => false,
        };
    }
}
