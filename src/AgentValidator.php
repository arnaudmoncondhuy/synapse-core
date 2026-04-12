<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;

/**
 * Service de validation des agents.
 *
 * Un agent est invalide quand il référence explicitement un preset qui lui
 * même n'est plus valide (provider désactivé, modèle désactivé, etc.). Les
 * agents qui laissent leur preset à null sont toujours considérés comme
 * valides puisqu'ils s'adossent au preset actif global.
 */
final class AgentValidator
{
    public function __construct(
        private readonly PresetValidator $presetValidator,
    ) {
    }

    public function isValid(SynapseAgent $agent): bool
    {
        $preset = $agent->getModelPreset();

        // Pas de preset explicite → délégation au preset actif global.
        // L'agent reste valide même si le global l'est ou non ; c'est au
        // pipeline global de gérer ce cas (ChatService).
        if (null === $preset) {
            return true;
        }

        return $this->presetValidator->isValid($preset);
    }

    public function getInvalidReason(SynapseAgent $agent): ?string
    {
        if ($this->isValid($agent)) {
            return null;
        }

        $preset = $agent->getModelPreset();
        if (null === $preset) {
            return null;
        }

        $reason = $this->presetValidator->getInvalidReason($preset);

        return sprintf(
            'Preset « %s » invalide : %s',
            $preset->getName(),
            $reason ?? 'raison inconnue'
        );
    }
}
