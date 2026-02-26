<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\VectorStore;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;

/**
 * Décorateur de VectorStoreInterface qui résout l'implémentation réelle
 * dynamiquement en fonction de la configuration en base de données.
 */
class DynamicVectorStore implements VectorStoreInterface
{
    public function __construct(
        private VectorStoreRegistry $registry,
        private SynapseConfigRepository $configRepository
    ) {}

    public function saveMemory(array $vector, array $payload): void
    {
        $this->getResolvedStore()->saveMemory($vector, $payload);
    }

    public function searchSimilar(array $vector, int $limit = 5, array $filters = []): array
    {
        return $this->getResolvedStore()->searchSimilar($vector, $limit, $filters);
    }

    /**
     * Résout l'implémentation de Vector Store à utiliser.
     */
    private function getResolvedStore(): VectorStoreInterface
    {
        $config = $this->configRepository->getGlobalConfig();
        $alias = $config->getVectorStore();

        return $this->registry->getVectorStore($alias);
    }
}
