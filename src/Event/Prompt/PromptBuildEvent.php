<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event\Prompt;

/**
 * Phase BUILD — Construction du prompt de base : system message, historique, tool definitions.
 *
 * Remplace SynapsePrePromptEvent priority 100 (ContextBuilderSubscriber).
 */
class PromptBuildEvent extends AbstractPromptEvent
{
}
