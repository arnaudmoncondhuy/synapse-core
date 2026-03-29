<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event\Prompt;

/**
 * Phase ENRICH — Enrichissement du contexte : mémoire utilisateur (prio 50), RAG (prio 40).
 *
 * Remplace SynapsePrePromptEvent priority 50 (MemoryContextSubscriber)
 *                             et priority 40 (RagContextSubscriber).
 *
 * Les priorités internes (50/40) sont conservées pour l'ordre mémoire → RAG.
 */
class PromptEnrichEvent extends AbstractPromptEvent
{
}
