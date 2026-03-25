<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface que l'application hôte implémente pour déclarer une source RAG.
 *
 * Chaque source fournit des documents que Synapse indexe (chunking + embedding)
 * et injecte automatiquement dans le contexte LLM des agents assignés.
 *
 * Utilisation :
 *   - Implémenter cette interface dans l'app hôte
 *   - Taguer le service avec `synapse.rag_source` (auto via autoconfigure)
 *   - Réindexer via `php bin/console synapse:rag:reindex <slug>`
 */
interface RagSourceProviderInterface
{
    /**
     * Identifiant unique de la source (slug, ex: 'drive_partage').
     */
    public function getSlug(): string;

    /**
     * Nom lisible de la source (ex: 'Documents Drive partagé').
     */
    public function getName(): string;

    /**
     * Description de la source, utilisée dans l'admin et le contexte LLM.
     */
    public function getDescription(): string;

    /**
     * Retourne les documents à indexer.
     *
     * Chaque document est un tableau associatif :
     *   - 'content' (string, requis) : le texte brut du document
     *   - 'sourceIdentifier' (string, requis) : clé de déduplication (ex: drive_file_id)
     *   - 'metadata' (array, optionnel) : métadonnées libres (filename, url, folder...)
     *
     * @return iterable<array{content: string, sourceIdentifier: string, metadata?: array<string, mixed>}>
     */
    public function fetchDocuments(): iterable;

    /**
     * Retourne le nombre total de documents à indexer, si connu.
     * Utile pour afficher une barre de progression.
     */
    public function countDocuments(): ?int;
}
