<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Enum\PromptVersionStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentPromptVersionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Snapshot historique du `systemPrompt` d'un {@see SynapseAgent}.
 *
 * Première pierre du Garde-fou #1 (voir `.evolutions/CRITICAL_GUARDRAILS.md`) :
 * chaque modification du prompt d'un agent — qu'elle vienne de l'admin humain,
 * d'un agent architecte, ou d'un outil MCP — persiste d'abord une version
 * immuable ici **avant** d'écraser la valeur courante sur l'agent. C'est ce qui
 * permet le rollback en 1 clic, le diff inter-versions, et l'audit trail
 * nécessaire avant toute activation d'auto-création (Phase 11).
 *
 * ## Propriétés
 *
 * - **Append-only** : une version n'est jamais modifiée après création.
 *   Supprimer des versions est un acte de maintenance (commande CLI future),
 *   pas une opération produit.
 * - **Relation à l'agent** : `nullable + onDelete: SET NULL`. Si l'agent est
 *   supprimé, l'historique survit via le champ dénormalisé `agentKey`. C'est
 *   la même stratégie que `SynapseWorkflowRun::$workflowKey`.
 * - **`isActive`** : une seule version peut être marquée active par agent à un
 *   instant T. C'est la version **actuellement appliquée** sur `SynapseAgent::$systemPrompt`.
 *   Permet d'afficher "version courante" dans l'historique sans recalculer via
 *   comparaison de contenu (qui casserait après un rollback puis re-modification
 *   identique). `PromptVersionRecorder` maintient l'invariant.
 * - **`changedBy`** : identifiant libre de l'auteur du changement. Convention :
 *   `human:<identifier>` pour un admin, `agent:<key>` pour un agent architecte,
 *   `mcp:<client>` pour un outil MCP distant, `system:migration` pour une
 *   opération automatique du bundle.
 * - **`reason`** : texte libre optionnel. L'UI admin le propose en champ requis
 *   (bonne pratique) mais l'entité l'accepte nullable pour ne pas bloquer les
 *   flux programmatiques (MCP, seed).
 *
 * ## Format du snapshot
 *
 * Pour l'instant, seul `systemPrompt` est snapshotté. Les autres champs (preset,
 * tools, RAG) évoluent à un rythme différent et ont leur propre historique
 * (spending limits, RAG sources). Une évolution future pourra étendre ce
 * snapshot à une map complète de la config agent si le besoin émerge.
 */
#[ORM\Entity(repositoryClass: SynapseAgentPromptVersionRepository::class)]
#[ORM\Table(name: 'synapse_agent_prompt_version')]
#[ORM\Index(name: 'idx_agent_prompt_version_agent', columns: ['agent_id'])]
#[ORM\Index(name: 'idx_agent_prompt_version_agent_key', columns: ['agent_key'])]
#[ORM\Index(name: 'idx_agent_prompt_version_created_at', columns: ['created_at'])]
class SynapseAgentPromptVersion
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Référence vers l'agent dont on snapshotte le prompt. Nullable pour survivre
     * à la suppression de l'agent parent (historique préservé via `agentKey`).
     */
    #[ORM\ManyToOne(targetEntity: SynapseAgent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SynapseAgent $agent = null;

    /**
     * Clé de l'agent dénormalisée à la prise du snapshot pour survivre à une
     * éventuelle suppression de l'agent parent.
     */
    #[ORM\Column(name: 'agent_key', type: Types::STRING, length: 50)]
    private string $agentKey = '';

    /**
     * Snapshot du `systemPrompt` à cet instant. Immuable après création.
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $systemPrompt = '';

    /**
     * Auteur du changement. Convention documentée dans le phpdoc de classe :
     * `human:<id>`, `agent:<key>`, `mcp:<client>`, `system:<source>`.
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $changedBy = 'system:unknown';

    /**
     * Raison libre du changement (optionnelle).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    /**
     * Version marquée comme **actuellement appliquée** sur l'agent.
     * Une seule version active par agent à un instant T — invariant maintenu
     * par `PromptVersionRecorder`.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isActive = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Score global attribué par le {@see \ArnaudMoncondhuy\SynapseCore\Contract\PromptJudgeInterface}
     * — valeur entre 0.0 et 10.0. NULL si aucun judge n'a scoré cette version
     * (judge désactivé, erreur transitoire swallowed, ou version antérieure à
     * l'introduction du Garde-fou #2).
     *
     * C'est un **signal informatif**, jamais bloquant : un score bas n'empêche
     * pas la sauvegarde, il sert à alerter l'humain dans l'admin et à générer des
     * métriques de dérive de qualité.
     */
    #[ORM\Column(type: Types::FLOAT, nullable: true)]
    private ?float $judgmentScore = null;

    /**
     * Résumé textuel du jugement du LLM reviewer (point par point, forces / faiblesses).
     * NULL si aucun judge ne s'est exprimé.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $judgmentRationale = null;

    /**
     * Jugement structuré complet (scores par critère, remarques, éventuelles
     * comparaisons avec la version précédente). Schéma libre, défini par
     * l'implémentation du judge.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $judgmentData = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $judgedAt = null;

    /**
     * Identifiant du juge — typiquement `model:<provider>/<modelName>` pour un
     * juge LLM, `heuristic:<name>` pour un juge programmatique, `null` si pas de
     * jugement.
     */
    #[ORM\Column(type: Types::STRING, length: 150, nullable: true)]
    private ?string $judgedBy = null;

    /**
     * État HITL (Garde-fou #3). NULL pour les versions créées en live mode direct
     * (edit admin humain), sinon l'un des états de {@see PromptVersionStatus}.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: PromptVersionStatus::class, nullable: true)]
    private ?PromptVersionStatus $status = null;

    /**
     * Identifiant de la personne (ou du système) qui a validé ou rejeté la
     * version HITL. Convention identique à `changedBy` : `human:<id>`,
     * `system:<source>`….
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $reviewedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAgent(): ?SynapseAgent
    {
        return $this->agent;
    }

    public function setAgent(?SynapseAgent $agent): self
    {
        $this->agent = $agent;
        if (null !== $agent) {
            $this->agentKey = $agent->getKey();
        }

        return $this;
    }

    public function getAgentKey(): string
    {
        return $this->agentKey;
    }

    public function setAgentKey(string $agentKey): self
    {
        $this->agentKey = $agentKey;

        return $this;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(string $systemPrompt): self
    {
        $this->systemPrompt = $systemPrompt;

        return $this;
    }

    public function getChangedBy(): string
    {
        return $this->changedBy;
    }

    public function setChangedBy(string $changedBy): self
    {
        $this->changedBy = $changedBy;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getJudgmentScore(): ?float
    {
        return $this->judgmentScore;
    }

    public function setJudgmentScore(?float $judgmentScore): self
    {
        $this->judgmentScore = $judgmentScore;

        return $this;
    }

    public function getJudgmentRationale(): ?string
    {
        return $this->judgmentRationale;
    }

    public function setJudgmentRationale(?string $judgmentRationale): self
    {
        $this->judgmentRationale = $judgmentRationale;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getJudgmentData(): ?array
    {
        return $this->judgmentData;
    }

    /**
     * @param array<string, mixed>|null $judgmentData
     */
    public function setJudgmentData(?array $judgmentData): self
    {
        $this->judgmentData = $judgmentData;

        return $this;
    }

    public function getJudgedAt(): ?\DateTimeImmutable
    {
        return $this->judgedAt;
    }

    public function setJudgedAt(?\DateTimeImmutable $judgedAt): self
    {
        $this->judgedAt = $judgedAt;

        return $this;
    }

    public function getJudgedBy(): ?string
    {
        return $this->judgedBy;
    }

    public function setJudgedBy(?string $judgedBy): self
    {
        $this->judgedBy = $judgedBy;

        return $this;
    }

    public function hasJudgment(): bool
    {
        return null !== $this->judgmentScore;
    }

    public function getStatus(): ?PromptVersionStatus
    {
        return $this->status;
    }

    public function setStatus(?PromptVersionStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function isPending(): bool
    {
        return PromptVersionStatus::Pending === $this->status;
    }

    public function getReviewedBy(): ?string
    {
        return $this->reviewedBy;
    }

    public function setReviewedBy(?string $reviewedBy): self
    {
        $this->reviewedBy = $reviewedBy;

        return $this;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function setReviewedAt(?\DateTimeImmutable $reviewedAt): self
    {
        $this->reviewedAt = $reviewedAt;

        return $this;
    }
}
