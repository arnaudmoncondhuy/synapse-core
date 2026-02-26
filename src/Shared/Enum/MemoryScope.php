<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Définit la portée (scope) d'un souvenir sémantique.
 */
enum MemoryScope: string
{
    /**
     * Portée globale à l'utilisateur. Le souvenir est disponible dans toutes ses conversations.
     */
    case USER = 'user';

    /**
     * Portée limitée à une conversation spécifique.
     */
    case CONVERSATION = 'conversation';
}
