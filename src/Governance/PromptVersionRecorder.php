<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance;

use ArnaudMoncondhuy\SynapseCore\Contract\PromptJudgeInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\PromptVersionStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentPromptVersion;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentPromptVersionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service de snapshot du `systemPrompt` d'un {@see SynapseAgent}.
 *
 * Premier maillon du Garde-fou #1 (voir `.evolutions/CRITICAL_GUARDRAILS.md`).
 * Chaque fois qu'une couche applicative (admin humain, outil MCP, agent architecte)
 * s'apprête à modifier le prompt d'un agent, elle **doit** appeler
 * {@see snapshot()} pour graver la version **qui s'apprête à devenir active**
 * avant d'écraser la valeur courante. Le rollback et le diff reposent
 * entièrement sur cette garantie — pas de snapshot = pas d'historique = pas
 * d'auto-modification sûre en Phase 11.
 *
 * ## Invariants maintenus
 *
 * 1. **Une seule version active par agent** : au moment où une nouvelle version
 *    devient active, toutes les autres versions du même agent sont désactivées
 *    dans la même transaction Doctrine.
 * 2. **Idempotence** : si la dernière version enregistrée a exactement le même
 *    `systemPrompt` que le nouvel état demandé, aucun nouveau snapshot n'est
 *    créé. Évite la pollution de l'historique sur des sauvegardes no-op du
 *    formulaire admin.
 * 3. **`agentKey` dénormalisé** : copié depuis l'agent au moment du snapshot
 *    pour survivre à une éventuelle suppression ultérieure de l'agent parent.
 *
 * ## Contrat d'appel
 *
 * Le service est **transactionnel côté caller** : il utilise l'`EntityManager`
 * injecté mais ne flush pas par défaut (le caller décide du timing pour
 * grouper avec ses propres écritures — typiquement la mise à jour de
 * `SynapseAgent::$systemPrompt` juste après). Un flag explicite `$flush = true`
 * est disponible pour les flux one-shot (outils MCP, commandes CLI).
 */
class PromptVersionRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SynapseAgentPromptVersionRepository $repository,
        private readonly PromptJudgeInterface $promptJudge,
    ) {
    }

    /**
     * Crée un nouveau snapshot du prompt qui s'apprête à devenir actif sur l'agent,
     * et désactive l'ancienne version active s'il y en avait une.
     *
     * À appeler **juste avant** d'écrire la nouvelle valeur sur
     * `SynapseAgent::$systemPrompt` — l'ordre importe pour que le snapshot
     * reflète bien la nouvelle version qui va devenir la vérité terrain.
     *
     * ## Mode `$pending = true` (Garde-fou #3 HITL)
     *
     * Quand `$pending = true`, le snapshot est créé avec `status = Pending` mais :
     *   - la version active courante N'EST PAS désactivée,
     *   - la nouvelle version N'EST PAS marquée active,
     *   - **le caller ne doit PAS modifier `SynapseAgent::$systemPrompt`** : la
     *     valeur live reste celle de la version active.
     *
     * La proposition entre dans la file d'attente admin et n'atterrit sur l'agent
     * que lorsqu'un humain appelle {@see approve()}.
     *
     * @param string $newPrompt la valeur qui sera appliquée sur l'agent après ce snapshot
     * @param string $changedBy identifiant de l'auteur — convention : `human:<id>`, `agent:<key>`, `mcp:<client>`, `system:<source>`
     * @param ?string $reason raison libre (optionnelle mais recommandée)
     * @param bool $flush flush immédiat de la transaction Doctrine ; false pour déléguer au caller
     * @param bool $pending si true, crée la version en état `Pending` (HITL) sans l'appliquer à l'agent
     *
     * @return SynapseAgentPromptVersion|null la version créée, ou null si
     *                                        idempotence déclenchée (pas de changement)
     */
    public function snapshot(
        SynapseAgent $agent,
        string $newPrompt,
        string $changedBy,
        ?string $reason = null,
        bool $flush = false,
        bool $pending = false,
    ): ?SynapseAgentPromptVersion {
        // Idempotence : si la dernière version enregistrée porte déjà ce prompt,
        // on ne crée pas de doublon. Couvre le cas "save du formulaire admin
        // sans modification réelle".
        // En mode pending, on ne court-circuite pas sur l'idempotence vs
        // la version active — une proposition peut être légitimement identique
        // à l'actuel (demande de confirmation explicite), mais on évite de
        // créer plusieurs pending identiques successifs.
        $latest = $this->repository->findLatestForAgent($agent);
        if (null !== $latest && $latest->getSystemPrompt() === $newPrompt && !$pending) {
            // Garantit quand même que la version latest est bien marquée active,
            // au cas où un état incohérent aurait été produit par une ancienne
            // route (avant ce garde-fou).
            if (!$latest->isActive()) {
                $this->markActive($agent, $latest);
                if ($flush) {
                    $this->entityManager->flush();
                }
            }

            return null;
        }

        $previousActive = $this->repository->findActiveForAgent($agent);

        // En mode live, on désactive l'ancienne version active avant d'en créer
        // une nouvelle. En mode pending, on LAISSE l'active en place — la
        // proposition n'est pas encore validée et ne doit pas impacter le runtime.
        if (!$pending && null !== $previousActive) {
            $previousActive->setIsActive(false);
        }

        $version = new SynapseAgentPromptVersion();
        $version->setAgent($agent); // populate agentKey via setter
        $version->setSystemPrompt($newPrompt);
        $version->setChangedBy($changedBy);
        $version->setReason($reason);
        $version->setIsActive(!$pending);
        if ($pending) {
            $version->setStatus(PromptVersionStatus::Pending);
        }

        // Garde-fou #2 : demande non-bloquante d'un jugement au reviewer.
        // L'implémentation NullPromptJudge est utilisée par défaut (retourne null)
        // et toute erreur côté ChatServiceBasedPromptJudge est swallowed — un
        // score manquant n'empêche jamais la sauvegarde du snapshot.
        $previousPromptForJudge = null !== $previousActive ? $previousActive->getSystemPrompt() : null;
        $judgment = $this->promptJudge->judge($agent, $newPrompt, $previousPromptForJudge);
        if (null !== $judgment) {
            $version->setJudgmentScore($judgment->score);
            $version->setJudgmentRationale($judgment->rationale);
            $version->setJudgmentData($judgment->data);
            $version->setJudgedAt(new \DateTimeImmutable());
            $version->setJudgedBy($judgment->judgedBy);
        }

        $this->entityManager->persist($version);

        if ($flush) {
            $this->entityManager->flush();
        }

        return $version;
    }

    /**
     * Approuve une version {@see PromptVersionStatus::Pending} : la fait passer
     * en `Approved`, la marque active et désactive la version active précédente.
     *
     * **Le caller reste responsable** de copier `$version->getSystemPrompt()`
     * sur `SynapseAgent::$systemPrompt` — c'est le même contrat que `snapshot()`
     * en mode live.
     *
     * @throws \LogicException si la version n'est pas en état Pending
     */
    public function approve(
        SynapseAgentPromptVersion $version,
        string $reviewedBy,
        bool $flush = false,
    ): void {
        if (PromptVersionStatus::Pending !== $version->getStatus()) {
            throw new \LogicException(sprintf('Cannot approve version #%s: expected status "pending", got "%s".', (string) $version->getId(), null !== $version->getStatus() ? $version->getStatus()->value : 'null'));
        }

        $agent = $version->getAgent();
        if (null === $agent) {
            throw new \LogicException(sprintf('Cannot approve version #%s: its parent agent has been deleted (orphan snapshot).', (string) $version->getId()));
        }

        $previousActive = $this->repository->findActiveForAgent($agent);
        if (null !== $previousActive && $previousActive->getId() !== $version->getId()) {
            $previousActive->setIsActive(false);
        }

        $version->setStatus(PromptVersionStatus::Approved);
        $version->setIsActive(true);
        $version->setReviewedBy($reviewedBy);
        $version->setReviewedAt(new \DateTimeImmutable());

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    /**
     * Rejette une version {@see PromptVersionStatus::Pending} : la fait passer
     * en `Rejected`, conservée pour l'audit, jamais active.
     *
     * @throws \LogicException si la version n'est pas en état Pending
     */
    public function reject(
        SynapseAgentPromptVersion $version,
        string $reviewedBy,
        ?string $rejectionReason = null,
        bool $flush = false,
    ): void {
        if (PromptVersionStatus::Pending !== $version->getStatus()) {
            throw new \LogicException(sprintf('Cannot reject version #%s: expected status "pending", got "%s".', (string) $version->getId(), null !== $version->getStatus() ? $version->getStatus()->value : 'null'));
        }

        $version->setStatus(PromptVersionStatus::Rejected);
        $version->setIsActive(false);
        $version->setReviewedBy($reviewedBy);
        $version->setReviewedAt(new \DateTimeImmutable());
        if (null !== $rejectionReason && '' !== $rejectionReason) {
            // On préfixe le motif de rejet sans écraser la raison d'origine (traçabilité).
            $existingReason = $version->getReason();
            $suffix = 'Rejected: '.$rejectionReason;
            $version->setReason(null !== $existingReason && '' !== $existingReason ? $existingReason."\n".$suffix : $suffix);
        }

        if ($flush) {
            $this->entityManager->flush();
        }
    }

    /**
     * Bascule l'état actif vers la version fournie et désactive toutes les
     * autres versions du même agent. Ne modifie PAS `SynapseAgent::$systemPrompt` —
     * c'est la responsabilité du caller (typiquement un contrôleur admin qui
     * copie ensuite le prompt sur l'entité agent).
     */
    public function markActive(SynapseAgent $agent, SynapseAgentPromptVersion $version): void
    {
        $previousActive = $this->repository->findActiveForAgent($agent);
        if (null !== $previousActive && $previousActive->getId() !== $version->getId()) {
            $previousActive->setIsActive(false);
        }
        $version->setIsActive(true);
    }
}
