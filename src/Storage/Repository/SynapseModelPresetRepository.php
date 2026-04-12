<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\PresetValidator;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\CannotActivateException;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\DeactivationCascade;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository pour les presets LLM.
 *
 * Un seul preset peut être actif à la fois (pas de scope).
 *
 * @extends ServiceEntityRepository<SynapseModelPreset>
 */
class SynapseModelPresetRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private SynapseAgentRepository $agentRepo,
        private PresetValidator $presetValidator,
    ) {
        parent::__construct($registry, SynapseModelPreset::class);
    }

    /**
     * Retourne le preset actif, ou en crée un par défaut si aucun n'existe.
     */
    public function findActive(): SynapseModelPreset
    {
        $preset = $this->findOneBy(['isActive' => true]);

        if (null !== $preset) {
            return $preset;
        }

        // Fallback : premier preset
        $preset = $this->findOneBy([], ['id' => 'ASC']);

        if (null !== $preset) {
            // Auto-activate it
            $preset->setIsActive(true);
            $this->getEntityManager()->flush();

            return $preset;
        }

        // Aucun preset — créer le défaut
        $preset = $this->createDefaultPreset();
        $em = $this->getEntityManager();
        $em->persist($preset);
        $em->flush();

        return $preset;
    }

    public function findByKey(string $key): ?SynapseModelPreset
    {
        return $this->findOneBy(['key' => $key]);
    }

    /**
     * Active un preset (et désactive tous les autres, car un seul preset
     * peut être actif à la fois).
     *
     * Centralise TOUTE la logique de validation d'activation : provider
     * configuré, modèle connu et non désactivé dans l'administration.
     *
     * @throws CannotActivateException si le preset échoue la validation
     */
    public function activate(SynapseModelPreset $preset): void
    {
        if (!$this->presetValidator->canBeActivated($preset)) {
            throw new CannotActivateException($preset->getName(), $this->presetValidator->getCannotActivateReason($preset) ?? 'preset invalide');
        }

        $em = $this->getEntityManager();

        // Désactiver tous les presets
        $em->createQuery(
            'UPDATE '.SynapseModelPreset::class.' p SET p.isActive = false'
        )->execute();

        // Activer le preset cible
        $preset->setIsActive(true);
        $em->flush();
    }

    /**
     * Désactive un preset et propage la désactivation aux agents qui le
     * référencent explicitement (et par ricochet à leurs workflows).
     *
     * Chaînage : seul le niveau n+1 (agents) est connu ici. Les niveaux
     * inférieurs (workflows) sont invisibles depuis ce repo — c'est le
     * {@see SynapseAgentRepository} qui se charge de les propager.
     *
     * Idempotent — un preset déjà inactif ne modifie rien côté preset, mais
     * la cascade vers les agents/workflows est toujours exécutée (défensif).
     * Le caller reste responsable du flush.
     */
    public function deactivate(SynapseModelPreset $preset): DeactivationCascade
    {
        $cascade = DeactivationCascade::empty();

        if ($preset->isActive()) {
            $preset->setIsActive(false);
            $cascade = $cascade->withPreset($preset->getName());
        }

        return $cascade->merge($this->agentRepo->deactivateAllByModelPreset($preset));
    }

    /**
     * Désactive en cascade tous les presets qui utilisent un modèle donné.
     *
     * Utilisé par le contrôleur admin au toggle d'un modèle : un seul appel
     * suffit pour couvrir l'ensemble de la chaîne (presets → agents →
     * workflows), sans que le contrôleur n'ait besoin d'importer les repos
     * des niveaux inférieurs.
     */
    public function deactivateAllByModel(string $providerName, string $modelId): DeactivationCascade
    {
        $cascade = DeactivationCascade::empty();
        foreach ($this->findBy(['providerName' => $providerName, 'model' => $modelId]) as $preset) {
            $cascade = $cascade->merge($this->deactivate($preset));
        }

        return $cascade;
    }

    /**
     * Tous les presets non-sandbox, triés par id. Utilisé pour l'admin.
     *
     * @return SynapseModelPreset[]
     */
    public function findAllPresets(): array
    {
        /** @var SynapseModelPreset[] $result */
        $result = $this->createQueryBuilder('p')
            ->andWhere('p.isSandbox = false')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Retourne tous les presets sandbox (pour le cleanup MCP).
     *
     * @return SynapseModelPreset[]
     */
    public function findSandbox(): array
    {
        return $this->findBy(['isSandbox' => true]);
    }

    /**
     * Crée un preset avec les valeurs par défaut.
     */
    private function createDefaultPreset(): SynapseModelPreset
    {
        $preset = new SynapseModelPreset();
        $preset->setName('Preset par défaut');
        $preset->setIsActive(true);

        // Trouver le premier provider actif
        $enabledProviders = $this->providerRepo->findEnabled();
        $providerName = '';
        $modelName = '';

        if (!empty($enabledProviders)) {
            $provider = $enabledProviders[0];
            $providerName = $provider->getName();

            // Trouver le premier modèle text-generation pour ce provider
            $models = $this->capabilityRegistry->getModelsForProvider($providerName);
            foreach ($models as $candidateModel) {
                $caps = $this->capabilityRegistry->getCapabilities($candidateModel);
                if ($caps->supportsTextGeneration && !$caps->isDeprecated()) {
                    $modelName = $candidateModel;
                    break;
                }
            }
        }

        $preset->setProviderName($providerName);
        $preset->setModel($modelName);

        $preset->setGenerationTemperature(1.0);
        $preset->setGenerationTopP(0.95);
        $preset->setGenerationTopK(40);

        return $preset;
    }
}
