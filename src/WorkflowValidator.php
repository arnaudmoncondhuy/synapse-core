<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Agent\CodeAgentRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflow;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;

/**
 * Service de validation des workflows.
 *
 * Un workflow est invalide quand au moins une de ses étapes référence un
 * `agent_name` qui n'est pas résolvable (ni agent code, ni agent DB existant)
 * ou qui pointe sur un agent DB actuellement désactivé.
 */
final class WorkflowValidator
{
    public function __construct(
        private readonly CodeAgentRegistry $codeAgents,
        private readonly SynapseAgentRepository $agentRepo,
    ) {
    }

    public function isValid(SynapseWorkflow $workflow): bool
    {
        return null === $this->findFirstInvalidStepReason($workflow);
    }

    public function getInvalidReason(SynapseWorkflow $workflow): ?string
    {
        return $this->findFirstInvalidStepReason($workflow);
    }

    /**
     * Parcourt les étapes et renvoie la raison d'invalidité de la PREMIÈRE
     * étape problématique, ou null si le workflow est intégralement valide.
     */
    private function findFirstInvalidStepReason(SynapseWorkflow $workflow): ?string
    {
        $definition = $workflow->getDefinition();
        $steps = $definition['steps'] ?? [];

        if (!is_array($steps)) {
            return null;
        }

        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }

            $agentName = $step['agent_name'] ?? null;
            if (!is_string($agentName) || '' === $agentName) {
                continue; // erreurs de schéma traitées ailleurs
            }

            // Un agent code (classe PHP enregistrée dans le registre) est
            // toujours considéré valide : il ne peut pas être désactivé depuis
            // l'admin et existe tant que le conteneur tourne.
            if ($this->codeAgents->has($agentName)) {
                continue;
            }

            $dbAgent = $this->agentRepo->findByKey($agentName);
            if (null === $dbAgent) {
                return sprintf('Étape « %s » : agent « %s » introuvable', (string) ($step['name'] ?? '?'), $agentName);
            }

            if (!$dbAgent->isActive()) {
                return sprintf('Étape « %s » : agent « %s » désactivé', (string) ($step['name'] ?? '?'), $agentName);
            }
        }

        return null;
    }
}
