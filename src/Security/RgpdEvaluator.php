<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Security;

use ArnaudMoncondhuy\SynapseCore\Contract\RgpdAwareInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\LlmClientRegistry;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\RgpdInfo;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseProviderRepository;

/**
 * Service partagé d'évaluation RGPD des presets LLM.
 *
 * Délègue l'évaluation au client LLM concerné (via RgpdAwareInterface).
 * Utilisé par GdprController et ConfigurationLlmController.
 */
final class RgpdEvaluator
{
    public function __construct(
        private readonly LlmClientRegistry $clientRegistry,
        private readonly SynapseProviderRepository $providerRepo,
    ) {
    }

    /**
     * Évalue la conformité RGPD d'un preset.
     * Retourne null si le client LLM ne supporte pas l'évaluation RGPD.
     */
    public function evaluate(SynapseModelPreset $preset): ?RgpdInfo
    {
        try {
            $client = $this->clientRegistry->getClientByProvider($preset->getProviderName());
        } catch (\RuntimeException) {
            return null;
        }

        if (!$client instanceof RgpdAwareInterface) {
            return null;
        }

        $provider = $this->providerRepo->findByName($preset->getProviderName());

        return $client->getRgpdInfo(
            $provider?->getCredentials() ?? [],
            $preset->getProviderOptions() ?? [],
            $preset->getModel(),
        );
    }

    /**
     * Retourne uniquement les presets dont le statut RGPD n'est pas 'compliant'.
     *
     * @param SynapseModelPreset[] $presets
     *
     * @return array<int, array{preset: SynapseModelPreset, rgpd: RgpdInfo}>
     */
    public function getWarnings(array $presets): array
    {
        $warnings = [];
        foreach ($presets as $preset) {
            $rgpd = $this->evaluate($preset);
            if (null !== $rgpd && !$rgpd->isCompliant()) {
                $warnings[] = ['preset' => $preset, 'rgpd' => $rgpd];
            }
        }

        return $warnings;
    }
}
