<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseProvider>
 */
class SynapseProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseProvider::class);
    }

    /**
     * Trouve un provider par son slug (ex : 'gemini', 'ovh').
     */
    public function findByName(string $name): ?SynapseProvider
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Retourne tous les providers activés.
     *
     * @return SynapseProvider[]
     */
    public function findEnabled(): array
    {
        return $this->findBy(['isEnabled' => true]);
    }

    /**
     * Retourne tous les providers (pour l'admin).
     *
     * @return SynapseProvider[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC']);
    }
}
