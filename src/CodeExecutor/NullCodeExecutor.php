<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\CodeExecutor;

use ArnaudMoncondhuy\SynapseCore\Contract\CodeExecutorInterface;

/**
 * Implémentation par défaut de {@see CodeExecutorInterface} : **refuse**
 * d'exécuter quoi que ce soit et retourne un `ExecutionResult` d'erreur
 * explicite.
 *
 * ## Pourquoi un null executor ?
 *
 * Chantier E est livré en scaffolding : le contrat est posé, mais aucun
 * backend réel (E2B / Docker / Firecracker) n'est câblé. Plutôt que d'avoir
 * `CodeExecuteTool` qui crashe au premier appel, on le fait retourner un
 * message clair : « l'exécution de code est désactivée dans cette instance,
 * contactez l'admin pour activer un backend ».
 *
 * Ce null executor est l'alias par défaut de `CodeExecutorInterface` dans
 * le conteneur. Quand un backend réel sera câblé, l'alias changera de
 * cible — pas le code appelant.
 *
 * ## Anti-pattern consciemment évité
 *
 * Ce null executor ne doit **pas** être tenté par « juste faire un
 * `eval($code)` temporairement pour débloquer le dev ». Exécuter du code
 * généré par LLM dans le process PHP de l'app est le vecteur d'attaque
 * le plus évident qui soit (RCE instantanée). Si tu as besoin de tester
 * `CodeExecuteTool` de bout en bout, câble un vrai backend Docker avec
 * les garde-fous de sécurité listés dans {@see CodeExecutorInterface}.
 */
final class NullCodeExecutor implements CodeExecutorInterface
{
    public function execute(string $code, string $language = 'python', array $inputs = [], array $options = []): ExecutionResult
    {
        return ExecutionResult::backendUnavailable(
            'Code execution is disabled in this Synapse instance. '
            .'No CodeExecutor backend is configured. Configure one of: '
            .'E2B (SaaS, quick), Docker (local, autonomous), or Firecracker (microVM, hardened).'
        );
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getSupportedLanguages(): array
    {
        return [];
    }
}
