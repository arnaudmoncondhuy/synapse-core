<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Impl;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;

/**
 * Fournisseur de contexte par défaut (Minimaliste).
 *
 * Cette classe est utilisée si l'application hôte ne fournit pas sa propre implémentation.
 * Elle injecte un prompt système basique et la date courante.
 */
class DefaultContextProvider implements ContextProviderInterface
{
    public function getSystemPrompt(): string
    {
        $now = new \DateTimeImmutable('now');
        $dateStr = $now->format('d/m/Y H:i');

        return <<<PROMPT
Tu es un assistant IA utile et compétent.
Date et heure actuelles : {$dateStr}.

Sois concis et utile dans ta réponse finale. Si tu ne sais pas quelque chose, dis-le simplement.
Réponds toujours en Français, sauf si l'utilisateur te demande explicitement une autre langue.
PROMPT;
    }

    public function getInitialContext(): array
    {
        return [];
    }
}
