<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

/**
 * Dispatché par {@see \ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool::execute()}
 * juste après chaque exécution de code via le {@see \ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface}
 * (Chantier E phase 2 — finition principe 8).
 *
 * Porte le code source brut écrit par le LLM + le résultat complet de
 * l'exécution (stdout, stderr, return_value, timing, error_type). Le
 * listener NDJSON dans `ChatApiController` convertit en event `code_execute`
 * pour que la transparency sidebar puisse afficher une carte dédiée avec :
 *
 * - le code Python en syntax-highlighted monospace,
 * - le stdout (ou empty state si silencieux),
 * - le `return_value` mis en évidence (convention `result = ...`),
 * - un bandeau d'erreur si la run a échoué.
 *
 * Ce rendu dédié est le pendant visuel du `Bash` tool dans Claude Code :
 * le user voit exactement ce que l'agent a tenté d'exécuter, sans devoir
 * parser un JSON brut dans les turns.
 */
final class SynapseCodeExecutedEvent
{
    /**
     * @param array<string, mixed> $result Tableau retourné par `ExecutionResult::toArray()`
     */
    public function __construct(
        public readonly string $code,
        public readonly string $language,
        public readonly array $result,
    ) {
    }

    /**
     * Sérialisation en array pour le streaming NDJSON vers le front.
     *
     * Aligne les clés sur ce que le listener JS
     * `synapse_chat_controller.js::renderCodeExecution()` attend.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'language' => $this->language,
            'result' => $this->result,
        ];
    }
}
