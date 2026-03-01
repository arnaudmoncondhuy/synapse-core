<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Fenêtre temporelle d'un plafond de dépense.
 */
enum SpendingLimitPeriod: string
{
    /** Fenêtre glissante en heures (durée configurable via synapse.token_tracking.sliding_day_hours, défaut 4h) */
    case SLIDING_DAY = 'sliding_day';

    /** Derniers 30 jours (glissante) */
    case SLIDING_MONTH = 'sliding_month';

    /** Jour calendaire (00:00–23:59) */
    case CALENDAR_DAY = 'calendar_day';

    /** Mois calendaire (1er–dernier du mois) */
    case CALENDAR_MONTH = 'calendar_month';
}
