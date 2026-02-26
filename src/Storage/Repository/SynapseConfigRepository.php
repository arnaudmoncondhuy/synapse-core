<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour la configuration globale Synapse (singleton)
 *
 * Un seul enregistrement dans cette table : les paramètres applicatifs globaux
 * (rétention RGPD, langue du contexte, prompt système personnalisé).
 *
 * @extends ServiceEntityRepository<SynapseConfig>
 */
class SynapseConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseConfig::class);
    }

    /**
     * Retourne la configuration globale (singleton), ou la crée avec les valeurs par défaut.
     */
    public function getGlobalConfig(): SynapseConfig
    {
        $config = $this->findOneBy([]);

        if ($config !== null) {
            return $config;
        }

        // Aucune config — créer la défaut
        $config = $this->createDefaultConfig();
        $em = $this->getEntityManager();
        $em->persist($config);
        $em->flush();

        return $config;
    }

    /**
     * Crée une config avec les valeurs par défaut.
     */
    private function createDefaultConfig(): SynapseConfig
    {
        $config = new SynapseConfig();
        $config->setRetentionDays(30);
        $config->setContextLanguage('fr');
        $config->setSystemPrompt(null);

        return $config;
    }
}
