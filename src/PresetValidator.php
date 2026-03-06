<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
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
        private SynapseProviderRepository $providerRepo,
        private ModelCapabilityRegistry $capabilityRegistry,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Vérifie si un preset est valide.
     */
    public function isValid(SynapsePreset $preset): bool
    {
        $providerName = $preset->getProviderName();
        $model = $preset->getModel();

        if (empty($providerName) || empty($model)) {
            return false;
        }

        $provider = $this->providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider || !$provider->isConfigured()) {
            return false;
        }

        return $this->capabilityRegistry->isKnownModel($model);
    }

    /**
     * Retourne la raison pour laquelle un preset est invalide.
     */
    public function getInvalidReason(SynapsePreset $preset): ?string
    {
        if ($this->isValid($preset)) {
            return null;
        }

        $providerName = $preset->getProviderName();
        $model = $preset->getModel();

        if (empty($providerName) || empty($model)) {
            if (empty($providerName) && empty($model)) {
                return 'Pas de provider ou de modèle configuré';
            }

            return empty($providerName) ? 'Aucun fournisseur défini' : 'Aucun modèle défini';
        }

        $provider = $this->providerRepo->findOneBy(['name' => $providerName]);
        if (!$provider) {
            return 'Fournisseur "'.$providerName.'" introuvable';
        }
        if (!$provider->isConfigured()) {
            return 'Fournisseur "'.$provider->getLabel().'" non configuré';
        }

        if (!$this->capabilityRegistry->isKnownModel($model)) {
            return 'Modèle "'.$model.'" inexistant ou désactivé';
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
    public function ensureActivePresetIsValid(SynapsePreset $activePreset): void
    {
        // Si le preset actif est valide, OK
        if ($this->isValid($activePreset)) {
            return;
        }

        // ⚠️ Le preset actif est INVALIDE → le désactiver
        $activePreset->setIsActive(false);
        $this->em->flush();

        // 🔍 Chercher un autre preset valide pour l'activer
        $repo = $this->em->getRepository(SynapsePreset::class);
        /** @var \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository $repo */
        $allPresets = $repo->findAll();
        foreach ($allPresets as $preset) {
            if ($preset->getId() === $activePreset->getId()) {
                continue; // Sauter le preset qu'on vient de désactiver
            }
            if ($this->isValid($preset)) {
                $preset->setIsActive(true);
                $this->em->flush();

                return;
            }
        }

        // ❌ Aucun preset valide trouvé
        throw new \Exception('Aucun preset valide n\'existe. Configurez un fournisseur et un modèle valides.');
    }
}
