<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Exception;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentContext;

/**
 * Levée par {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver::resolve()}
 * quand un agent tente d'en invoquer un autre au-delà de la profondeur maximale
 * autorisée.
 *
 * Implémente le garde-fou #5 documenté dans `.evolutions/CRITICAL_GUARDRAILS.md`.
 * La profondeur max est configurée via `synapse.agents.max_depth`
 * (défaut : {@see AgentContext::DEFAULT_MAX_DEPTH}).
 */
final class AgentDepthExceededException extends \RuntimeException
{
    public static function forContext(string $requestedName, AgentContext $context): self
    {
        return new self(sprintf(
            'Cannot resolve agent "%s": maximum agent-in-agent depth of %d reached (current depth: %d). '
            .'This is guardrail #5 — raise synapse.agents.max_depth if this composition is intentional.',
            $requestedName,
            $context->getMaxDepth(),
            $context->getDepth(),
        ));
    }
}
