<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Disponibilité d'un outil (Function Calling) dans le contexte de conversation.
 *
 * Le code enregistre tous les outils via le tag DI `synapse.tool`, mais
 * l'administration peut ensuite restreindre leur exposition runtime via
 * {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseToolConfig}.
 */
enum ToolAvailability: string
{
    /**
     * Outil désactivé : jamais exposé à l'IA, même si un agent le whiteliste.
     */
    case DISABLED = 'disabled';

    /**
     * Outil actif par défaut : exposé à l'IA dans toutes les conversations
     * (sauf si un agent définit une whitelist qui l'exclut).
     */
    case ACTIVE = 'active';

    /**
     * Outil disponible uniquement si un agent l'a explicitement whitelisté
     * via {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent::getAllowedToolNames()}.
     * Jamais exposé en tchat normal (sans agent ou agent sans restrictions).
     */
    case AGENT_ONLY = 'agent_only';

    /**
     * Libellé lisible pour l'UI admin.
     */
    public function label(): string
    {
        return match ($this) {
            self::DISABLED => 'Désactivé',
            self::ACTIVE => 'Actif',
            self::AGENT_ONLY => 'Uniquement sur agent autorisé',
        };
    }

    /**
     * Description courte affichée dans le sélecteur admin.
     */
    public function description(): string
    {
        return match ($this) {
            self::DISABLED => 'Jamais exposé à l\'IA, même si un agent le whiteliste.',
            self::ACTIVE => 'Exposé à l\'IA dans toutes les conversations.',
            self::AGENT_ONLY => 'Disponible seulement si un agent le whiteliste explicitement.',
        };
    }

    /**
     * Classe CSS du badge admin associé à chaque état.
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::DISABLED => 'danger',
            self::ACTIVE => 'success',
            self::AGENT_ONLY => 'warning',
        };
    }

    /**
     * Icône Lucide associée à chaque état.
     */
    public function icon(): string
    {
        return match ($this) {
            self::DISABLED => 'circle-off',
            self::ACTIVE => 'circle-check',
            self::AGENT_ONLY => 'user-check',
        };
    }
}
