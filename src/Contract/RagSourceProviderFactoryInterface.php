<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour les factories de sources RAG dynamiques.
 *
 * Permet de créer des providers RAG à partir d'une source de données runtime
 * (base de données, API, fichier de config…) sans les déclarer statiquement
 * comme services Symfony.
 *
 * Cas d'usage typique : plusieurs sources Drive stockées en base, chacune
 * devenant un RagSourceProviderInterface au moment de la résolution.
 *
 * Utilisation :
 *   - Implémenter cette interface dans l'app hôte
 *   - Taguer avec `synapse.rag_source_factory` (auto via autoconfigure)
 *   - Le RagSourceRegistry résoudra les providers à la première utilisation (lazy)
 */
interface RagSourceProviderFactoryInterface
{
    /**
     * Crée et retourne les providers RAG gérés par cette factory.
     *
     * @return iterable<RagSourceProviderInterface>
     */
    public function createProviders(): iterable;
}
