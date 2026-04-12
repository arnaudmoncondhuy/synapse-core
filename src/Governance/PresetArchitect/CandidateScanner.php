<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect;

use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;

/**
 * Scanne les providers configurés et retourne la liste des modèles
 * candidats pour un preset de chat (text-generation, non-deprecated, enabled).
 */
final class CandidateScanner
{
    public function __construct(
        private readonly SynapseProviderRepository $providerRepo,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
        private readonly SynapseModelRepository $modelRepo,
    ) {
    }

    /**
     * Scanne les modèles candidats pour un preset.
     *
     * @param string|null $providerFilter Filtrer par provider (ex: 'anthropic')
     * @param string $requiredCapability Capacité requise (ex: 'text_generation', 'embedding', 'image_generation')
     * @param bool $rgpdSensitive Si true, exclut les modèles à risque RGPD (danger + risk). Ne garde que null (EU) et tolerated.
     *
     * @return list<array{modelId: string, provider: string, providerLabel: string, capabilities: \ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities}>
     */
    public function scan(
        ?string $providerFilter = null,
        string $requiredCapability = 'text_generation',
        bool $rgpdSensitive = false,
    ): array {
        $enabledProviders = $this->providerRepo->findEnabled();
        $candidates = [];

        // Collecter les modèles désactivés en admin pour filtrage
        $disabledModels = $this->getDisabledModelIds();

        foreach ($enabledProviders as $provider) {
            if (!$provider->isConfigured()) {
                continue;
            }

            $providerName = $provider->getName();

            if (null !== $providerFilter && $providerName !== $providerFilter) {
                continue;
            }

            $models = $this->capabilityRegistry->getModelsForProvider($providerName);

            foreach ($models as $modelId) {
                $caps = $this->capabilityRegistry->getCapabilities($modelId);

                if (!$caps->supports($requiredCapability)) {
                    continue;
                }

                if ($caps->isDeprecated()) {
                    continue;
                }

                if ($rgpdSensitive && \in_array($caps->rgpdRisk, ['danger', 'risk'], true)) {
                    continue;
                }

                if (isset($disabledModels[$providerName.':'.$modelId])) {
                    continue;
                }

                $candidates[] = [
                    'modelId' => $modelId,
                    'provider' => $providerName,
                    'providerLabel' => $provider->getLabel(),
                    'capabilities' => $caps,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, true>
     */
    private function getDisabledModelIds(): array
    {
        $disabled = [];
        $allModels = $this->modelRepo->findAll();

        foreach ($allModels as $model) {
            if (!$model->isEnabled()) {
                $disabled[$model->getProviderName().':'.$model->getModelId()] = true;
            }
        }

        return $disabled;
    }
}
