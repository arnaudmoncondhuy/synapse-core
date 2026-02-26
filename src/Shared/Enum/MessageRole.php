<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Rôle d'un message dans une conversation IA.
 *
 * Définit l'identité de l'émetteur du message pour que le LLM comprenne la structure
 * de l'échange. Synapse normalise ces rôles vers les formats spécifiques des fournisseurs.
 */
enum MessageRole: string
{
    /**
     * Message envoyé par l'utilisateur humain.
     */
    case USER = 'USER';

    /**
     * Message généré par l'Intelligence Artificielle.
     */
    case MODEL = 'MODEL';

    /**
     * Instructions système ou contexte global injecté (ex: "Tu es un pirate").
     */
    case SYSTEM = 'SYSTEM';

    /**
     * Représente un appel de fonction par l'IA ou le résultat renvoyé par un outil.
     */
    case FUNCTION = 'FUNCTION';

    /**
     * Indique si ce rôle a vocation à être affiché dans une interface de chat classique.
     */
    public function isDisplayable(): bool
    {
        return match ($this) {
            self::USER, self::MODEL => true,
            self::SYSTEM, self::FUNCTION => false,
        };
    }

    /**
     * Retourne l'émoji représentatif pour les logs ou l'UI.
     */
    public function getEmoji(): string
    {
        return match ($this) {
            self::USER => '👤',
            self::MODEL => '🤖',
            self::SYSTEM => '⚙️',
            self::FUNCTION => '🔧',
        };
    }

    /**
     * Nom lisible du rôle pour l'administration.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::USER => 'Utilisateur',
            self::MODEL => 'Assistant',
            self::SYSTEM => 'Système',
            self::FUNCTION => 'Fonction',
        };
    }
}
