<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Rag;

use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderFactoryInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;

/**
 * Registre centralisé des sources RAG.
 *
 * Deux modes d'enregistrement :
 *   1. Statique : services taggués `synapse.rag_source` (injectés au boot)
 *   2. Dynamique : factories taggées `synapse.rag_source_factory` (résolues lazily
 *      à la première utilisation, permettant des sources issues de la DB)
 */
class RagSourceRegistry
{
    /** @var array<string, RagSourceProviderInterface> */
    private array $providers = [];

    private bool $factoriesLoaded = false;

    /**
     * @param iterable<RagSourceProviderInterface> $staticProviders
     * @param iterable<RagSourceProviderFactoryInterface> $factories
     */
    public function __construct(
        iterable $staticProviders,
        private readonly iterable $factories,
    ) {
        foreach ($staticProviders as $provider) {
            $this->providers[$provider->getSlug()] = $provider;
        }
    }

    public function get(string $slug): ?RagSourceProviderInterface
    {
        if (!isset($this->providers[$slug])) {
            $this->loadFromFactories();
        }

        return $this->providers[$slug] ?? null;
    }

    public function has(string $slug): bool
    {
        if (!isset($this->providers[$slug])) {
            $this->loadFromFactories();
        }

        return isset($this->providers[$slug]);
    }

    /**
     * Retourne tous les providers (statiques + dynamiques).
     *
     * @return array<string, RagSourceProviderInterface>
     */
    public function getAll(): array
    {
        $this->loadFromFactories();

        return $this->providers;
    }

    /**
     * Charge les providers depuis les factories (une seule fois).
     */
    private function loadFromFactories(): void
    {
        if ($this->factoriesLoaded) {
            return;
        }

        $this->factoriesLoaded = true;

        foreach ($this->factories as $factory) {
            foreach ($factory->createProviders() as $provider) {
                // Les providers statiques ont la priorité
                if (!isset($this->providers[$provider->getSlug()])) {
                    $this->providers[$provider->getSlug()] = $provider;
                }
            }
        }
    }
}
