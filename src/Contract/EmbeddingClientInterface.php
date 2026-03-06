<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour les clients de génération d'embeddings vectoriels.
 *
 * Cette interface permet de normaliser la communication avec différents
 * modèles d'embedding (Vertex AI, OpenAI, HuggingFace) pour transformer
 * du texte en vecteurs numériques.
 */
interface EmbeddingClientInterface
{
    /**
     * Identifiant interne du fournisseur (ex: 'gemini', 'openai').
     */
    public function getProviderName(): string;

    /**
     * Génère des embeddings vectoriels pour un ou plusieurs textes d'entrée.
     *
     * @param string|array<int, string> $input   texte unique ou liste de textes à vectoriser
     * @param string|null               $model   identifiant du modèle (ex: 'text-multilingual-embedding-002')
     * @param array<string, mixed>      $options options (ex: dimensionality, task_type)
     *
     * @return array{
     *     embeddings: list<list<float>>,
     *     usage: array{prompt_tokens: int, total_tokens: int}
     * } Structure de retour normalisée
     */
    public function generateEmbeddings(string|array $input, ?string $model = null, array $options = []): array;
}
