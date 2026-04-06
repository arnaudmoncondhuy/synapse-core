<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\Exception;

/**
 * Levée par {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver::resolve()}
 * quand aucun agent code ni agent BDD ne correspond au nom demandé.
 *
 * Miroir conceptuel de `Symfony\AI\Agent\Exception\*` (namespace aligné
 * dans `packages/core/src/Agent/Exception/`).
 */
final class AgentNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('No agent registered under name "%s".', $name));
    }
}
