<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\CodeExecutor\ExecutionResult;

/**
 * Exécute du code arbitraire (généralement Python) dans un environnement isolé.
 *
 * Chantier E — Vague 2 — **scaffolding uniquement**. Ce contrat est posé
 * **sans implémentation sandbox réelle**. L'implémentation par défaut fournie
 * ({@see \ArnaudMoncondhuy\SynapseCore\CodeExecutor\NullCodeExecutor}) refuse
 * d'exécuter quoi que ce soit et retourne une erreur explicite. C'est
 * intentionnel : le choix du backend (E2B / Docker / Firecracker) a des
 * trade-offs sécurité/setup qui doivent être pris en décision explicite,
 * pas par défaut.
 *
 * ## Pourquoi poser l'interface maintenant
 *
 * - Permet aux chantiers amont (Chantier D autonomie notamment) de câbler
 *   `code_execute` dans les `allowedTools` d'un agent sans attendre le
 *   backend définitif.
 * - Le `CodeExecuteTool` (voir {@see \ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool})
 *   peut être référencé partout dès maintenant ; il échoue gracefully tant
 *   que le `NullCodeExecutor` est en place.
 * - Le jour où on câble un vrai backend, c'est un simple `alias` DI à
 *   changer — pas un refacto.
 *
 * ## Contraintes de sécurité à faire respecter par toute implémentation réelle
 *
 * 1. **Pas d'accès réseau** par défaut (whitelist explicite uniquement).
 * 2. **Pas d'accès filesystem hôte**. Tmpfs éphémère uniquement.
 * 3. **Limite mémoire hard** (cgroup ou équivalent) — pas de swap infini.
 * 4. **Limite CPU hard** — wall clock timeout ET cpu quota.
 * 5. **Pas de privilèges** — user non-root, no-new-privileges, seccomp default.
 * 6. **Stdout/stderr capturés et taille-limités** (typiquement 1 MB max par
 *    stream) pour éviter qu'un `while True: print("x")` sature la RAM du
 *    process parent.
 * 7. **Audit trail** — chaque exécution persiste la paire (code, résultat)
 *    liée au `SynapseDebugLog` parent via `workflow_run_id`.
 *
 * ## Backends envisagés (décision différée)
 *
 * - **E2B** ({@link https://e2b.dev}) : SaaS, rapide à câbler, facture à la
 *   seconde. Idéal pour démarrer, pas idéal en autonomie locale.
 * - **Docker éphémère** : conteneur one-shot avec `--rm --network none
 *   --memory 512m --cpus 0.5 --user 1000 --security-opt no-new-privileges`.
 *   Autonome mais setup docker requis sur l'hôte.
 * - **Firecracker microVM** : le plus sécurisé (VM complète, pas juste un
 *   namespace). Setup le plus lourd. Overkill pour un bac à sable personnel.
 *
 * Au moment de trancher : documenter dans `packages/core/docs/security/code_execution.md`
 * avec le modèle de menace retenu.
 */
interface CodeExecutorInterface
{
    /**
     * Exécute `$code` dans l'environnement isolé géré par l'implémentation.
     *
     * @param string $code Le code source à exécuter. Syntaxe dépend de `$language`.
     * @param string $language Langage du code (défaut `python`). Une implémentation
     *                         peut refuser un langage qu'elle ne supporte pas en
     *                         retournant un `ExecutionResult` marqué `failed = true`.
     * @param array<string, mixed> $inputs Valeurs injectées dans l'environnement d'exécution
     *                                     (pour Python : variables globales ou `sys.argv`,
     *                                     selon l'implémentation). Doivent être JSON-sérialisables.
     * @param array<string, mixed> $options Options opaques spécifiques à l'implémentation
     *                                      (timeout override, memory_limit override, etc.). Les
     *                                      implémentations doivent ignorer silencieusement les
     *                                      clés qu'elles ne reconnaissent pas.
     */
    public function execute(string $code, string $language = 'python', array $inputs = [], array $options = []): ExecutionResult;

    /**
     * Retourne `true` si l'exécuteur est opérationnel (backend connecté,
     * authentifié, capacité disponible). Un `false` ici doit pousser le
     * caller à dégrader proprement (message d'erreur au LLM plutôt que
     * timeout silencieux).
     */
    public function isAvailable(): bool;

    /**
     * Retourne la liste des langages supportés par cet exécuteur.
     *
     * @return list<string>
     */
    public function getSupportedLanguages(): array;
}
