<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Étapes de statut émises pendant la génération (streaming).
 */
enum StatusStep: string
{
    case THINKING = 'thinking';
    case GENERATING = 'generating';

    public static function tool(string $name): string
    {
        return 'tool:'.$name;
    }
}
