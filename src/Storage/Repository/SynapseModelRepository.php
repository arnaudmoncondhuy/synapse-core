<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynapseModel>
 */
class SynapseModelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynapseModel::class);
    }

    /**
     * Retourne les modèles activés pour un provider donné.
     *
     * @return SynapseModel[]
     */
    public function findEnabledForProvider(string $providerName): array
    {
        return $this->findBy(
            ['providerName' => $providerName, 'isEnabled' => true],
            ['sortOrder' => 'ASC', 'label' => 'ASC']
        );
    }

    /**
     * Retourne tous les modèles groupés par provider (pour l'admin).
     *
     * @return array<string, SynapseModel[]>
     */
    public function findAllGroupedByProvider(): array
    {
        $models = $this->findBy([], ['providerName' => 'ASC', 'sortOrder' => 'ASC']);
        $grouped = [];

        foreach ($models as $model) {
            $grouped[$model->getProviderName()][] = $model;
        }

        return $grouped;
    }

    /**
     * Retourne une map [model_id => ['input' => x, 'output' => y, 'output_image' => z|null, 'currency' => c]] des tarifs.
     *
     * `output_image` est null si le modèle n'a pas de tarif image dédié — dans ce cas
     * les tokens image sont facturés au tarif `output` (fallback).
     *
     * @return array<string, array{input: float, output: float, output_image: float|null, currency: string}>
     */
    public function findAllPricingMap(): array
    {
        $models = $this->findAll();
        $map = [];

        foreach ($models as $model) {
            if (null !== $model->getPricingInput() || null !== $model->getPricingOutput() || null !== $model->getPricingOutputImage()) {
                $map[$model->getModelId()] = [
                    'input' => $model->getPricingInput() ?? 0.0,
                    'output' => $model->getPricingOutput() ?? 0.0,
                    'output_image' => $model->getPricingOutputImage(),
                    'currency' => $model->getCurrency(),
                ];
            }
        }

        return $map;
    }
}
