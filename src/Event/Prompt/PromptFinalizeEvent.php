<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event\Prompt;

/**
 * Phase FINALIZE — Directives finales : master prompt injecté en fin de system message.
 *
 * Remplace SynapsePrePromptEvent priority -75 (MasterPromptSubscriber).
 */
class PromptFinalizeEvent extends AbstractPromptEvent
{
}
