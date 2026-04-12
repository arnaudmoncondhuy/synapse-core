<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * VO immuable qui accumule les entités désactivées lors d'un chaînage.
 *
 * Le chaînage fonctionne par ruissellement : chaque niveau (preset → agent →
 * workflow) désactive ses propres entités et fusionne le cascade renvoyé par
 * le niveau inférieur. Le controller tout en haut de la chaîne reçoit au final
 * la liste complète des noms désactivés à chaque niveau, sans avoir besoin de
 * connaître ni d'importer les repos des niveaux > n+1.
 *
 * @see \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository::deactivate()
 * @see \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository::deactivate()
 * @see \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository::deactivate()
 */
final class DeactivationCascade
{
    /**
     * @param string[] $presets Noms des presets désactivés
     * @param string[] $agents Noms des agents désactivés
     * @param string[] $workflows Noms des workflows désactivés
     */
    public function __construct(
        public readonly array $presets = [],
        public readonly array $agents = [],
        public readonly array $workflows = [],
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    public function withPreset(string $name): self
    {
        return new self(
            [...$this->presets, $name],
            $this->agents,
            $this->workflows,
        );
    }

    public function withAgent(string $name): self
    {
        return new self(
            $this->presets,
            [...$this->agents, $name],
            $this->workflows,
        );
    }

    public function withWorkflow(string $name): self
    {
        return new self(
            $this->presets,
            $this->agents,
            [...$this->workflows, $name],
        );
    }

    public function merge(self $other): self
    {
        return new self(
            [...$this->presets, ...$other->presets],
            [...$this->agents, ...$other->agents],
            [...$this->workflows, ...$other->workflows],
        );
    }

    public function isEmpty(): bool
    {
        return [] === $this->presets && [] === $this->agents && [] === $this->workflows;
    }
}
