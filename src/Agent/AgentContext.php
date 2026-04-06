<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

/**
 * Contexte d'exécution transporté entre les appels d'agents.
 *
 * VO immutable porteur des informations runtime qui ne doivent PAS faire partie
 * de l'entrée métier ({@see Input}) : traçabilité arborescente, budget, profondeur
 * de composition, identité de l'appelant.
 *
 * **Contrainte forte** : tous les champs sont purement scalaires (ou des tableaux
 * de scalaires) pour permettre une sérialisation Messenger native. Ne jamais y
 * placer d'entités Doctrine, de services ou d'objets métier complexes.
 *
 * Ce VO n'existe pas dans `symfony/ai` — c'est une extension Synapse qui sera
 * transportée via la clé `$options['context']` de {@see AgentInterface::call()},
 * pour rester compatible mot-pour-mot avec la signature de leur interface.
 *
 * @author Synapse Bundle
 */
final class AgentContext
{
    /**
     * Profondeur maximale par défaut d'agent-sur-agent.
     *
     * Sert de fallback si {@see self::$maxDepth} n'est pas surchargé via la config
     * `synapse.agents.max_depth`. Garde-fou #5 documenté dans CRITICAL_GUARDRAILS.md.
     */
    public const DEFAULT_MAX_DEPTH = 2;

    /**
     * @param string $requestId UUID / identifiant unique de cette exécution (propagé dans les logs)
     * @param string|null $userId Identifiant de l'utilisateur appelant (null = système)
     * @param string|null $parentRunId `agent_run_id` du parent si cet appel est imbriqué
     * @param string|null $workflowRunId UUID du workflow englobant si on est dans un workflow
     * @param int $depth Profondeur courante (0 = appel racine)
     * @param int $maxDepth Profondeur maximale autorisée
     * @param int|null $budgetTokensRemaining Budget tokens restant hérité, null si illimité
     * @param string $origin Origine de l'appel : 'direct' | 'code' | 'config' | 'ephemeral' | 'workflow'
     */
    public function __construct(
        private readonly string $requestId,
        private readonly ?string $userId = null,
        private readonly ?string $parentRunId = null,
        private readonly ?string $workflowRunId = null,
        private readonly int $depth = 0,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private readonly ?int $budgetTokensRemaining = null,
        private readonly string $origin = 'direct',
    ) {
    }

    /**
     * Crée un contexte racine (depth=0, pas de parent) avec un requestId généré.
     */
    public static function root(
        ?string $userId = null,
        int $maxDepth = self::DEFAULT_MAX_DEPTH,
        ?int $budgetTokensRemaining = null,
        string $origin = 'direct',
    ): self {
        return new self(
            requestId: self::generateRequestId(),
            userId: $userId,
            depth: 0,
            maxDepth: $maxDepth,
            budgetTokensRemaining: $budgetTokensRemaining,
            origin: $origin,
        );
    }

    /**
     * Crée un contexte enfant pour un appel imbriqué (un agent en invoque un autre).
     *
     * Incrémente la profondeur, préserve le user et le workflow englobant,
     * remet à jour l'origine selon le type d'agent résolu.
     *
     * @param string $parentRunId `agent_run_id` du parent (obligatoire pour l'arborescence)
     */
    public function createChild(string $parentRunId, string $childOrigin = 'code'): self
    {
        return new self(
            requestId: self::generateRequestId(),
            userId: $this->userId,
            parentRunId: $parentRunId,
            workflowRunId: $this->workflowRunId,
            depth: $this->depth + 1,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $this->budgetTokensRemaining,
            origin: $childOrigin,
        );
    }

    /**
     * Retourne une copie immutable du contexte avec `workflowRunId` renseigné.
     *
     * Utilisé par le futur moteur `MultiAgent` (Phase 8) au démarrage d'un workflow :
     * il propage le `workflowRunId` fraîchement généré dans le contexte avant de
     * déléguer à chaque agent d'étape. Tous les {@see SynapseDebugLog} produits par
     * ces appels verront alors leur champ `workflow_run_id` renseigné, permettant
     * de reconstituer l'arbre complet d'un run.
     *
     * Ne peut pas être utilisée pour *désassigner* un workflow — pour cela, créer
     * un nouveau contexte racine.
     */
    public function withWorkflowRunId(string $workflowRunId): self
    {
        return new self(
            requestId: $this->requestId,
            userId: $this->userId,
            parentRunId: $this->parentRunId,
            workflowRunId: $workflowRunId,
            depth: $this->depth,
            maxDepth: $this->maxDepth,
            budgetTokensRemaining: $this->budgetTokensRemaining,
            origin: $this->origin,
        );
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function getParentRunId(): ?string
    {
        return $this->parentRunId;
    }

    public function getWorkflowRunId(): ?string
    {
        return $this->workflowRunId;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }

    public function getBudgetTokensRemaining(): ?int
    {
        return $this->budgetTokensRemaining;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function hasParent(): bool
    {
        return null !== $this->parentRunId;
    }

    public function isDepthExceeded(): bool
    {
        return $this->depth >= $this->maxDepth;
    }

    private static function generateRequestId(): string
    {
        // Préférence pour uuid natif PHP 8, fallback sur une version random_bytes.
        if (function_exists('uuid_create')) {
            /** @var string $uuid */
            $uuid = uuid_create();

            return $uuid;
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
