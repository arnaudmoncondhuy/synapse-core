<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Governance\PromptJudgment;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;

/**
 * Contrat d'un juge qui évalue la qualité d'un `systemPrompt` d'agent — Garde-fou #2.
 *
 * L'idée : chaque fois qu'un prompt est modifié (admin humain, agent architecte,
 * outil MCP…), un juge indépendant (typiquement un LLM reviewer distinct du modèle
 * cible) note la version générée. Le verdict est stocké sur
 * {@see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion} et
 * affiché dans l'admin pour alerter l'humain en cas de dérive de qualité.
 *
 * ## Contrat de robustesse
 *
 * - **Non-bloquant** : l'implémentation NE DOIT JAMAIS propager une exception.
 *   Elle retourne `null` si elle ne peut pas rendre un verdict (juge désactivé,
 *   quota LLM épuisé, timeout, réponse malformée). Le recorder continue son
 *   fonctionnement normal.
 * - **Idempotent** : appeler deux fois avec les mêmes arguments peut retourner
 *   deux verdicts différents (le LLM est stochastique), c'est attendu.
 * - **Synchrone pour le MVP** : l'appelant (typiquement `PromptVersionRecorder`)
 *   peut choisir de réaliser l'appel en synchrone ou via un handler Messenger
 *   selon le contexte (admin web vs CLI). L'interface elle-même ne préjuge pas.
 *
 * ## Implémentations disponibles
 *
 * - {@see \ArnaudMoncondhuy\SynapseCore\Governance\NullPromptJudge} — no-op, sélectionné
 *   par défaut tant qu'aucune implémentation productive n'est câblée.
 * - {@see \ArnaudMoncondhuy\SynapseCore\Governance\ChatServiceBasedPromptJudge} — utilise
 *   `ChatService` avec JSON Mode (Phase 6) pour scorer via un LLM reviewer.
 */
interface PromptJudgeInterface
{
    /**
     * Évalue un prompt et retourne un verdict structuré.
     *
     * @param SynapseAgent $agent Agent ciblé (fournit contexte, nom, description)
     * @param string $newPrompt Le prompt nouvellement proposé
     * @param string|null $previousPrompt Le prompt précédent si une version existait (permet une évaluation différentielle)
     *
     * @return PromptJudgment|null verdict si le juge a pu s'exprimer, `null` sinon (swallowed)
     */
    public function judge(SynapseAgent $agent, string $newPrompt, ?string $previousPrompt): ?PromptJudgment;
}
