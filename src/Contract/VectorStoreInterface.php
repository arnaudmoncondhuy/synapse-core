<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour l'Inversion de Contrôle de la mémoire vectorielle (RAG).
 *
 * Permet au bundle de déléguer le stockage et la recherche de similitude vectorielle
 * à l'application hôte (ex: via PostgreSQL/pgvector, ChromaDB, Pinecone).
 */
interface VectorStoreInterface
{
    /**
     * Enregistre un vecteur d'embedding et ses métadonnées associées.
     *
     * @param array<int, float>    $vector  Le vecteur numérique généré par le modèle d'embedding.
     * @param array<string, mixed> $payload Métadonnées (texte original, identifiants, source).
     */
    public function saveMemory(array $vector, array $payload): void;

    /**
     * Effectue une recherche de similitude (Nearest Neighbors).
     *
     * @param array<int, float>    $vector  Le vecteur de requête (recherche).
     * @param int                  $limit   Nombre maximum de résultats à retourner.
     * @param array<string, mixed> $filters filtres additionnels (ex: ['document_id' => '...']).
     *
     * @return array<int, array{payload: array<string, mixed>, score: float}> Liste des résultats triés par pertinence (score).
     */
    public function searchSimilar(array $vector, int $limit = 5, array $filters = []): array;
}
