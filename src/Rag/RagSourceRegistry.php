<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Rag;

use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;

/**
 * Registre centralisé des sources RAG déclarées par l'application hôte.
 *
 * Collecte les services taggués `synapse.rag_source` via le container DI.
 */
class RagSourceRegistry
{
    /** @var array<string, RagSourceProviderInterface> */
    private array $providers = [];

    /**
     * @param iterable<RagSourceProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->getSlug()] = $provider;
        }
    }

    public function get(string $slug): ?RagSourceProviderInterface
    {
        return $this->providers[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        return isset($this->providers[$slug]);
    }

    /**
     * @return RagSourceProviderInterface[]
     */
    public function getAll(): array
    {
        return $this->providers;
    }
}
