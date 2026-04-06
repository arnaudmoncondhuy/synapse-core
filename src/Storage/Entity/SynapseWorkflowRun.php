<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\WorkflowRunStatus;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Instance d'exécution d'un {@see SynapseWorkflow}.
 *
 * Un run représente une exécution factuelle, mutable pendant qu'elle tourne (statut,
 * step courant, timings) et immuable une fois terminée (un statut terminal bloque
 * toute modification sémantique). Chaque run porte un UUID (`workflow_run_id`) qui
 * est propagé dans {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext::$workflowRunId}
 * et retrouvé sur chaque {@see SynapseDebugLog::$workflowRunId} produit par les appels
 * LLM de ses étapes — permettant de reconstituer l'arbre complet d'un workflow.
 *
 * **Phase 7** : l'entité existe et est lisible dans l'admin (vue read-only des runs).
 * Aucun code de production ne crée encore de `SynapseWorkflowRun` — c'est Phase 8
 * qui branchera le moteur `MultiAgent`.
 *
 * Relation au workflow : `nullable: true + onDelete: SET NULL`. Si la définition
 * est supprimée, les runs historiques survivent via le champ dénormalisé
 * `workflowKey`. Jamais de cascade remove.
 */
#[ORM\Entity(repositoryClass: SynapseWorkflowRunRepository::class)]
#[ORM\Table(name: 'synapse_workflow_run')]
#[ORM\Index(name: 'idx_workflow_run_status', columns: ['status'])]
#[ORM\Index(name: 'idx_workflow_run_started_at', columns: ['started_at'])]
#[ORM\Index(name: 'idx_workflow_run_workflow_key', columns: ['workflow_key'])]
class SynapseWorkflowRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * UUID identifiant cette exécution logique. Propagé dans `AgentContext::$workflowRunId`
     * et référencé par `SynapseDebugLog::$workflowRunId`.
     */
    #[ORM\Column(name: 'workflow_run_id', type: Types::STRING, length: 36, unique: true)]
    private string $workflowRunId;

    /**
     * Référence vers la définition du workflow. Nullable pour survivre à la
     * suppression de la définition parente (historique préservé via `workflowKey`).
     */
    #[ORM\ManyToOne(targetEntity: SynapseWorkflow::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SynapseWorkflow $workflow = null;

    /**
     * Clé du workflow dénormalisée au démarrage du run pour survivre à une
     * éventuelle suppression de la définition.
     */
    #[ORM\Column(name: 'workflow_key', type: Types::STRING, length: 100)]
    private string $workflowKey = '';

    /**
     * Version de la définition au moment où le run a démarré (snapshot).
     * Permet de savoir quelle version du workflow a réellement été exécutée.
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $workflowVersion = 1;

    /**
     * Statut courant du run.
     */
    #[ORM\Column(type: Types::STRING, length: 20, enumType: WorkflowRunStatus::class)]
    private WorkflowRunStatus $status = WorkflowRunStatus::PENDING;

    /**
     * Index de l'étape en cours d'exécution (0-based). -1 = pas encore démarré,
     * `stepsCount` après la dernière étape terminée.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $currentStepIndex = 0;

    /**
     * Nombre total d'étapes du workflow au démarrage du run. Dénormalisé pour
     * l'affichage de progression `currentStepIndex / stepsCount`.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $stepsCount = 0;

    /**
     * Input initial du workflow (payload utilisateur).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $input = null;

    /**
     * Output final agrégé (map des outputs clés → valeurs des étapes).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $output = null;

    /**
     * Message d'erreur en cas de `status = FAILED`.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /**
     * Identifiant utilisateur déclencheur (null = système/CLI/cron).
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $userId = null;

    /**
     * Total de tokens consommés par les étapes (dénormalisé depuis les runs).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $totalTokens = null;

    /**
     * Coût estimé du run dans la devise de référence (snapshot).
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?string $totalCost = null;

    public function __construct()
    {
        $this->workflowRunId = Uuid::v4()->toRfc4122();
        $this->startedAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflowRunId(): string
    {
        return $this->workflowRunId;
    }

    public function getWorkflow(): ?SynapseWorkflow
    {
        return $this->workflow;
    }

    public function setWorkflow(?SynapseWorkflow $workflow): self
    {
        $this->workflow = $workflow;
        if (null !== $workflow) {
            $this->workflowKey = $workflow->getWorkflowKey();
            $this->workflowVersion = $workflow->getVersion();
            $this->stepsCount = $workflow->getStepsCount();
        }

        return $this;
    }

    public function getWorkflowKey(): string
    {
        return $this->workflowKey;
    }

    public function setWorkflowKey(string $workflowKey): self
    {
        $this->workflowKey = $workflowKey;

        return $this;
    }

    public function getWorkflowVersion(): int
    {
        return $this->workflowVersion;
    }

    public function setWorkflowVersion(int $workflowVersion): self
    {
        $this->workflowVersion = $workflowVersion;

        return $this;
    }

    public function getStatus(): WorkflowRunStatus
    {
        return $this->status;
    }

    public function setStatus(WorkflowRunStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrentStepIndex(): int
    {
        return $this->currentStepIndex;
    }

    public function setCurrentStepIndex(int $currentStepIndex): self
    {
        $this->currentStepIndex = $currentStepIndex;

        return $this;
    }

    public function getStepsCount(): int
    {
        return $this->stepsCount;
    }

    public function setStepsCount(int $stepsCount): self
    {
        $this->stepsCount = $stepsCount;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getInput(): ?array
    {
        return $this->input;
    }

    /**
     * @param array<string, mixed>|null $input
     */
    public function setInput(?array $input): self
    {
        $this->input = $input;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOutput(): ?array
    {
        return $this->output;
    }

    /**
     * @param array<string, mixed>|null $output
     */
    public function setOutput(?array $output): self
    {
        $this->output = $output;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function setCompletedAt(?\DateTimeImmutable $completedAt): self
    {
        $this->completedAt = $completedAt;

        return $this;
    }

    public function getUserId(): ?string
    {
        return $this->userId;
    }

    public function setUserId(?string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(?int $totalTokens): self
    {
        $this->totalTokens = $totalTokens;

        return $this;
    }

    public function getTotalCost(): ?float
    {
        return null !== $this->totalCost ? (float) $this->totalCost : null;
    }

    public function setTotalCost(?float $totalCost): self
    {
        $this->totalCost = null !== $totalCost ? (string) $totalCost : null;

        return $this;
    }

    /**
     * Durée totale en secondes (null si le run n'est pas encore terminé).
     */
    public function getDurationSeconds(): ?float
    {
        if (null === $this->completedAt) {
            return null;
        }

        return (float) ($this->completedAt->getTimestamp() - $this->startedAt->getTimestamp())
            + ($this->completedAt->format('u') - $this->startedAt->format('u')) / 1_000_000;
    }
}
