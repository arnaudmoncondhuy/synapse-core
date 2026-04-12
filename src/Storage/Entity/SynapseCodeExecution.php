<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseCodeExecutionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Audit trail d'une exécution de code via l'outil `code_execute` (Chantier E).
 *
 * Chaque appel à {@see \ArnaudMoncondhuy\SynapseCore\Tool\CodeExecuteTool::execute()}
 * persiste une ligne ici avec le code source, la langue, et le résultat complet
 * de l'exécution. Permet un audit a posteriori : qu'a exécuté le LLM, quand,
 * avec quel résultat, lié à quel turn de conversation.
 *
 * ## Relation avec SynapseDebugLog
 *
 * `SynapseDebugLog` trace les **appels LLM** (requête + réponse + tokens).
 * `SynapseCodeExecution` trace les **exécutions de code** qui en découlent.
 * Les deux sont liés via `debugId` (dénormalisé, non FK hard) pour pouvoir
 * reconstituer « quand le LLM X a répondu Y, il a exécuté le code Z ».
 *
 * Pas de FK Doctrine pour la même raison que `SynapseDebugLog.workflow_run_id` :
 * garder les deux tables autonomes et éviter les cascades delete.
 *
 * ## Pourquoi une entité dédiée plutôt qu'une colonne JSON sur DebugLog ?
 *
 * - **Volumétrie** : un turn LLM = 1 ligne debug_log, mais N exécutions code
 *   (un agent peut call `code_execute` plusieurs fois dans un même turn).
 * - **Requêtabilité** : on veut pouvoir filtrer « toutes les exécutions qui
 *   ont produit une erreur `PythonSyntaxError` », « tout le code qui utilise
 *   `import requests` » — plus naturel sur une table qu'en JSON nested.
 * - **Taille** : le code peut être long (1 MB stdout truncate). Mettre ça
 *   dans le JSON `data` du debug_log alourdirait chaque lecture de run.
 *
 * ## Retention
 *
 * Pas de retention auto pour l'instant. Les lignes s'accumulent. Pour un
 * usage personnel (basile), ça tient largement. Si ça devient un problème,
 * ajouter un `synapse:code_execution:gc --older-than=90d`.
 */
#[ORM\Entity(repositoryClass: SynapseCodeExecutionRepository::class)]
#[ORM\Table(name: 'synapse_code_execution')]
#[ORM\Index(name: 'idx_code_exec_debug_id', columns: ['debug_id'])]
#[ORM\Index(name: 'idx_code_exec_workflow_run', columns: ['workflow_run_id'])]
#[ORM\Index(name: 'idx_code_exec_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_code_exec_success', columns: ['success'])]
class SynapseCodeExecution
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * ID du debug log LLM qui a déclenché cette exécution. Permet de
     * remonter à l'appel LLM qui a écrit ce code. Dénormalisé, pas FK.
     */
    #[ORM\Column(name: 'debug_id', type: Types::STRING, length: 50, nullable: true)]
    private ?string $debugId = null;

    /**
     * ID du run de workflow dans lequel l'exécution a eu lieu (si applicable).
     * Permet de reconstituer l'ensemble des exécutions code d'un workflow.
     */
    #[ORM\Column(name: 'workflow_run_id', type: Types::STRING, length: 36, nullable: true)]
    private ?string $workflowRunId = null;

    /**
     * Code source exécuté (tel quel, pas de troncature — on veut l'audit complet).
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $code = '';

    /**
     * Langage du code. Aujourd'hui seul `python` est supporté, mais réservé
     * pour extension future (javascript, bash, etc.).
     */
    #[ORM\Column(type: Types::STRING, length: 20)]
    private string $language = 'python';

    /**
     * `true` si l'exécution s'est terminée sans erreur fatale.
     */
    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $success = false;

    /**
     * Sortie standard capturée (tronquée côté sandbox à 1 MB).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $stdout = null;

    /**
     * Sortie d'erreur capturée (tronquée).
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $stderr = null;

    /**
     * Valeur retournée via la convention `result = ...` (extraite par le
     * wrapper Python côté sandbox). JSON-sérialisable ou `repr()` en fallback.
     */
    #[ORM\Column(name: 'return_value', type: Types::JSON, nullable: true)]
    private mixed $returnValue = null;

    /**
     * Durée d'exécution réelle en millisecondes (wall-clock, mesurée côté sandbox).
     */
    #[ORM\Column(name: 'duration_ms', type: Types::INTEGER)]
    private int $durationMs = 0;

    /**
     * Classe d'erreur en cas d'échec (`TimeoutException`, `PythonSyntaxError`,
     * `BackendUnavailable`, etc.). NULL si `success = true`.
     */
    #[ORM\Column(name: 'error_type', type: Types::STRING, length: 50, nullable: true)]
    private ?string $errorType = null;

    /**
     * Message d'erreur lisible. NULL si succès.
     */
    #[ORM\Column(name: 'error_message', type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDebugId(): ?string
    {
        return $this->debugId;
    }

    public function setDebugId(?string $debugId): self
    {
        $this->debugId = $debugId;

        return $this;
    }

    public function getWorkflowRunId(): ?string
    {
        return $this->workflowRunId;
    }

    public function setWorkflowRunId(?string $workflowRunId): self
    {
        $this->workflowRunId = $workflowRunId;

        return $this;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function setSuccess(bool $success): self
    {
        $this->success = $success;

        return $this;
    }

    public function getStdout(): ?string
    {
        return $this->stdout;
    }

    public function setStdout(?string $stdout): self
    {
        $this->stdout = $stdout;

        return $this;
    }

    public function getStderr(): ?string
    {
        return $this->stderr;
    }

    public function setStderr(?string $stderr): self
    {
        $this->stderr = $stderr;

        return $this;
    }

    public function getReturnValue(): mixed
    {
        return $this->returnValue;
    }

    public function setReturnValue(mixed $returnValue): self
    {
        $this->returnValue = $returnValue;

        return $this;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getErrorType(): ?string
    {
        return $this->errorType;
    }

    public function setErrorType(?string $errorType): self
    {
        $this->errorType = $errorType;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
