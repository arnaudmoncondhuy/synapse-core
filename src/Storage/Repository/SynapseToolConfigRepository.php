<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ToolAvailability;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseToolConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseToolConfig>
 */
class SynapseToolConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseToolConfig::class);
    }

    public function findByToolName(string $toolName): ?SynapseToolConfig
    {
        return $this->findOneBy(['toolName' => $toolName]);
    }

    /**
     * Retourne une map [toolName => ToolAvailability] pour lookup O(1).
     *
     * @return array<string, ToolAvailability>
     */
    public function getAvailabilityMap(): array
    {
        $map = [];
        foreach ($this->findAll() as $config) {
            $map[$config->getToolName()] = $config->getAvailability();
        }

        return $map;
    }

    /**
     * @return SynapseToolConfig[]
     */
    public function findAllOrderedByName(): array
    {
        return $this->findBy([], ['toolName' => 'ASC']);
    }
}
