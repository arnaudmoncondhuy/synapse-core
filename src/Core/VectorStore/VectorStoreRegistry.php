<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\VectorStore;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;

/**
 * Registre des implémentations de Vector Store disponibles.
 */
class VectorStoreRegistry
{
    /** @var array<string, VectorStoreInterface> */
    private array $vectorStores = [];

    /**
     * @param iterable<string, VectorStoreInterface> $vectorStores
     */
    public function __construct(iterable $vectorStores)
    {
        foreach ($vectorStores as $alias => $vectorStore) {
            $this->vectorStores[$alias] = $vectorStore;
        }
    }

    /**
     * Retourne une instance de Vector Store par son alias.
     */
    public function getVectorStore(string $alias): VectorStoreInterface
    {
        if (!isset($this->vectorStores[$alias])) {
            // Fallback sur doctrine si l'alias n'est pas trouvé
            return $this->vectorStores['doctrine'] ?? reset($this->vectorStores);
        }

        return $this->vectorStores[$alias];
    }

    /**
     * Retourne tous les alias disponibles.
     *
     * @return string[]
     */
    public function getAvailableAliases(): array
    {
        return array_keys($this->vectorStores);
    }
}
