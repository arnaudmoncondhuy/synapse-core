<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event\Prompt;

/**
 * Phase OPTIMIZE — Optimisation du prompt : troncation du contexte selon la context window.
 *
 * Remplace SynapsePrePromptEvent priority -50 (ContextTruncationSubscriber).
 */
class PromptOptimizeEvent extends AbstractPromptEvent
{
}
