<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\Wizard;

/**
 * Résultat standardisé produit par un wizard (preset, agent, workflow).
 *
 * Ce VO constitue le contrat commun entre les wizards indépendants
 * et le futur wizard "Mission" qui les orchestrera.
 */
final readonly class WizardResult
{
    /**
     * @param array<string, mixed> $entityData
     */
    public function __construct(
        /** Type d'entité créée : 'agent', 'preset', 'workflow' */
        public string $entityType,
        /** Clé technique de l'entité */
        public string $key,
        /** Nom lisible de l'entité */
        public string $name,
        /** Données complètes de l'entité (champs du formulaire) */
        public array $entityData,
        /** true si le LLM a participé à la génération */
        public bool $llmAssisted,
        /** Justification ou raisonnement (LLM ou heuristique) */
        public ?string $reasoning = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'key' => $this->key,
            'name' => $this->name,
            'entity_data' => $this->entityData,
            'llm_assisted' => $this->llmAssisted,
            'reasoning' => $this->reasoning,
        ];
    }
}
