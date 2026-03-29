<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Devises supportées pour le tracking des coûts LLM.
 */
enum Currency: string
{
    case EUR = 'EUR';
    case USD = 'USD';
    case GBP = 'GBP';

    public function symbol(): string
    {
        return match ($this) {
            self::EUR => '€',
            self::USD => '$',
            self::GBP => '£',
        };
    }
}
