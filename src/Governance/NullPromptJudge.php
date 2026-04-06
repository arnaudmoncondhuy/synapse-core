<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance;

use ArnaudMoncondhuy\SynapseCore\Contract\PromptJudgeInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;

/**
 * Implémentation "no-op" du {@see PromptJudgeInterface}.
 *
 * Sélectionnée par défaut dans la DI tant qu'aucune implémentation productive
 * n'est câblée. Retourne systématiquement `null`, garantissant que le
 * `PromptVersionRecorder` fonctionne sans dépendance LLM — critique pour les
 * tests unitaires et les environnements dev sans quota.
 */
final class NullPromptJudge implements PromptJudgeInterface
{
    public function judge(SynapseAgent $agent, string $newPrompt, ?string $previousPrompt): ?PromptJudgment
    {
        return null;
    }
}
