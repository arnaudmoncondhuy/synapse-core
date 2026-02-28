<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseTone;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseTone>
 */
class SynapseToneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseTone::class);
    }

    /**
     * Retourne tous les tones actifs, triés par sortOrder puis par nom.
     *
     * @return SynapseTone[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Retourne tous les tones (actifs et inactifs), pour l'admin.
     *
     * @return SynapseTone[]
     */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['isBuiltin' => 'DESC', 'sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Trouve un tone par sa clé slug (ex : 'zen', 'efficace').
     */
    public function findByKey(string $key): ?SynapseTone
    {
        return $this->findOneBy(['key' => $key]);
    }
}
