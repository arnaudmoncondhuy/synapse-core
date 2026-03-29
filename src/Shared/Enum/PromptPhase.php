<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Phases du pipeline de construction du prompt.
 *
 * Remplace les priorités magiques (100, 50, 40, -50, -75, -200) par un ordre explicite.
 */
enum PromptPhase: string
{
    /** Construire le prompt de base (system + history + tools). */
    case BUILD = 'build';

    /** Enrichir avec contexte (mémoire, RAG, custom). */
    case ENRICH = 'enrich';

    /** Optimiser (troncation, token budget). */
    case OPTIMIZE = 'optimize';

    /** Directives finales (master prompt). */
    case FINALIZE = 'finalize';

    /** Capture debug (lecture seule). */
    case CAPTURE = 'capture';
}
