<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Statut d'exécution d'un {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseWorkflowRun}.
 *
 * Aucun moteur ne consomme encore ces statuts en Phase 7 (seulement stockage et
 * affichage admin). Phase 8 (MultiAgent) fera transitionner les runs.
 */
enum WorkflowRunStatus: string
{
    /**
     * Run créé mais pas encore démarré (ex : enqueue Messenger, attente de worker).
     */
    case PENDING = 'pending';

    /**
     * Run en cours d'exécution : au moins une étape a démarré, aucune erreur fatale.
     */
    case RUNNING = 'running';

    /**
     * Toutes les étapes sont passées avec succès.
     */
    case COMPLETED = 'completed';

    /**
     * Une étape a échoué ou une exception a interrompu le run.
     */
    case FAILED = 'failed';

    /**
     * Annulation manuelle (admin, commande CLI) ou budget dépassé.
     */
    case CANCELLED = 'cancelled';

    /**
     * Clé de traduction affichable via `translator->trans($status->transKey(), domain: 'synapse_admin')`.
     */
    public function transKey(): string
    {
        return 'synapse.admin.workflow.status.'.$this->value;
    }

    /**
     * Statut final (plus aucune transition sortante possible).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            self::PENDING, self::RUNNING => false,
        };
    }
}
