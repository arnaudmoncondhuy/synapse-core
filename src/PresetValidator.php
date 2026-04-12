<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de validation des presets.
 *
 * Vérifie qu'un preset est valide (provider configuré + modèle existe)
 * et gère l'auto-correction si un preset valide devient invalide
 */
final class PresetValidator
{
    public function __construct(
        private readonly SynapseProviderRepository $providerRepo,
        private readonly SynapseModelRepository $modelRepo,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Vérifie si un preset est valide.
     */
    public function isValid(SynapseModelPreset $preset): bool
    {
        $providerName = $preset->getProviderName();
        $model = $preset->getModel();
        $key = $preset->getKey();

        if (empty($providerName) || empty($model) || empty($key)) {
            return false;
        }

        $provider = $this->providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider || !$provider->isConfigured()) {
            return false;
        }

        if (!$this->capabilityRegistry->isKnownModel($model)) {
            return false;
        }

        // Un modèle peut être désactivé dans l'admin (SynapseModel.isEnabled = false).
        // Dans ce cas le preset est invalide : on ne doit pas pouvoir l'activer ni
        // router des appels LLM vers lui.
        return $this->isModelEnabled($providerName, $model);
    }

    private function isModelEnabled(string $providerName, string $modelId): bool
    {
        $dbModel = $this->modelRepo->findOneBy([
            'providerName' => $providerName,
            'modelId' => $modelId,
        ]);

        // Absent de la table = activé par défaut (valeur de la colonne is_enabled).
        return null === $dbModel || $dbModel->isEnabled();
    }

    /**
     * Retourne la raison pour laquelle un preset est invalide.
     */
    public function getInvalidReason(SynapseModelPreset $preset): ?string
    {
        if ($this->isValid($preset)) {
            return null;
        }

        $providerName = $preset->getProviderName();
        $model = $preset->getModel();
        $key = $preset->getKey();

        if (empty($providerName) || empty($model) || empty($key)) {
            if (empty($providerName) && empty($model) && empty($key)) {
                return 'Configuration incomplète (fournisseur, modèle et clé technique requis)';
            }

            if (empty($key)) {
                return 'Clé technique (slug) manquante';
            }

            if (empty($providerName)) {
                return 'Aucun fournisseur défini';
            }

            return 'Aucun modèle défini';
        }

        $provider = $this->providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider) {
            return 'Fournisseur "'.$providerName.'" introuvable';
        }
        if (!$provider->isConfigured()) {
            return 'Fournisseur "'.$provider->getLabel().'" non configuré';
        }

        if (!$this->capabilityRegistry->isKnownModel($model)) {
            return 'Modèle "'.$model.'" inexistant';
        }

        if (!$this->isModelEnabled($providerName, $model)) {
            return 'Modèle "'.$model.'" désactivé dans l\'administration';
        }

        return null;
    }

    /**
     * Vérifie si un preset peut être activé comme preset par défaut.
     *
     * Un preset est activable s'il est valide ET que son modèle supporte
     * la génération de texte. Un preset image-only ou embedding-only peut
     * être valide (utilisable par un agent spécialisé) mais ne peut jamais
     * devenir le preset par défaut du système.
     */
    public function canBeActivated(SynapseModelPreset $preset): bool
    {
        if (!$this->isValid($preset)) {
            return false;
        }

        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());

        return $caps->supportsTextGeneration;
    }

    /**
     * Retourne la raison pour laquelle un preset ne peut pas être activé.
     */
    public function getCannotActivateReason(SynapseModelPreset $preset): ?string
    {
        $invalidReason = $this->getInvalidReason($preset);
        if (null !== $invalidReason) {
            return $invalidReason;
        }

        $caps = $this->capabilityRegistry->getCapabilities($preset->getModel());
        if (!$caps->supportsTextGeneration) {
            return 'Le modèle "'.$preset->getModel().'" ne supporte pas la génération de texte (embedding ou image uniquement)';
        }

        return null;
    }

    /**
     * 🛡️ DÉFENSE CRITIQUE : Vérifie et corrige un preset actif invalide.
     *
     * Si le preset actif est devenu invalide (provider désactivé, etc.),
     * le désactive automatiquement pour éviter les erreurs.
     *
     * @throws \Exception Si aucun preset valide n'existe
     */
    public function ensureActivePresetIsValid(SynapseModelPreset $activePreset): void
    {
        // Le preset actif doit être activable (valide + text-generation)
        if ($this->canBeActivated($activePreset)) {
            return;
        }

        // ⚠️ Le preset actif n'est pas activable → le désactiver
        $activePreset->setIsActive(false);
        $this->em->flush();

        // 🔍 Chercher un autre preset activable
        $repo = $this->em->getRepository(SynapseModelPreset::class);
        /** @var Storage\Repository\SynapseModelPresetRepository $repo */
        $allPresets = $repo->findAll();
        foreach ($allPresets as $preset) {
            if ($preset->getId() === $activePreset->getId()) {
                continue;
            }
            if ($this->canBeActivated($preset)) {
                $preset->setIsActive(true);
                $this->em->flush();

                return;
            }
        }

        // ❌ Aucun preset activable trouvé
        throw new \Exception('Aucun preset activable n\'existe. Configurez un fournisseur avec un modèle text-generation valide.');
    }
}
