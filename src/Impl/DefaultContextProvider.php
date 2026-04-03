<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Impl;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Fournisseur de contexte par défaut (Minimaliste).
 *
 * Cette classe est utilisée si l'application hôte ne fournit pas sa propre implémentation.
 * Elle injecte un prompt système basique et la date courante.
 */
#[AsAlias(id: ContextProviderInterface::class)]
class DefaultContextProvider implements ContextProviderInterface
{
    public function getSystemPrompt(): string
    {
        $now = new \DateTimeImmutable('now');
        $dateStr = $now->format('d/m/Y H:i');

        return <<<PROMPT
Date et heure actuelles : {$dateStr}
PROMPT;
    }

    public function getInitialContext(): array
    {
        return [];
    }
}
