<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Périmètre d'un plafond de dépense (user, preset ou agent).
 */
enum SpendingLimitScope: string
{
    case USER = 'user';
    case PRESET = 'preset';
    case AGENT = 'agent';
}
