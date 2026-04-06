<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseDebugLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journaux de debug persistants.
 *
 * Stocke les traces d'exécution des appels LLM (requête, réponse, paramètres effectivement envoyés).
 */
#[ORM\Entity(repositoryClass: SynapseDebugLogRepository::class)]
#[ORM\Table(name: 'synapse_debug_log')]
#[ORM\Index(name: 'idx_debug_log_parent_run', columns: ['parent_run_id'])]
#[ORM\Index(name: 'idx_debug_log_agent_run', columns: ['agent_run_id'])]
#[ORM\Index(name: 'idx_debug_log_workflow_run', columns: ['workflow_run_id'])]
class SynapseDebugLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifiant unique du debug (généré lors de l'appel LLM).
     */
    #[ORM\Column(type: Types::STRING, length: 50, unique: true)]
    private string $debugId;

    /**
     * ID de la conversation (optionnel, pour lier les appels à une conversation).
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $conversationId = null;

    /**
     * Module ayant déclenché l'appel (dénormalisé depuis data.module pour éviter le chargement du JSON en liste).
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $module = null;

    /**
     * Action précise au sein du module (ex: `chat_turn`, `title_generation`, `image_generation`, `rag_indexation`).
     *
     * Dénormalisé pour affichage en liste. Aligné sémantiquement sur `SynapseLlmCall::$action`
     * afin que les deux tables utilisent le même vocabulaire.
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $action = null;

    /**
     * Modèle utilisé (dénormalisé depuis data.model).
     */
    #[ORM\Column(type: Types::STRING, length: 100, nullable: true)]
    private ?string $model = null;

    /**
     * Total de tokens consommés (dénormalisé depuis data.usage).
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $totalTokens = null;

    /**
     * Timestamp de création.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * `agent_run_id` de l'exécution parente si cet appel est imbriqué
     * (un agent en a invoqué un autre via {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver}).
     *
     * NULL = appel racine. Permet de reconstituer l'arborescence d'exécution pour
     * les agents éphémères et les workflows. Correspond au champ `parentRunId` de
     * {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $parentRunId = null;

    /**
     * UUID identifiant une exécution logique d'agent (différent du `debug_id` qui
     * identifie un appel LLM précis : un agent peut enchaîner plusieurs appels LLM
     * comme {@see \ArnaudMoncondhuy\SynapseCore\Agent\PresetValidator\PresetValidatorAgent}
     * qui fait 3 steps, chacun produisant son propre `debug_id`, tous partageant le même `agent_run_id`).
     *
     * Correspond au `requestId` de {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $agentRunId = null;

    /**
     * Profondeur d'imbrication agent-sur-agent (0 = appel racine).
     *
     * Plafonnée par `synapse.agents.max_depth` (garde-fou #5).
     */
    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $depth = 0;

    /**
     * Origine de l'appel : 'direct' | 'code' | 'config' | 'ephemeral' | 'workflow'.
     *
     * Correspond au champ `origin` de {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}.
     */
    #[ORM\Column(type: Types::STRING, length: 20, options: ['default' => 'direct'])]
    private string $origin = 'direct';

    /**
     * UUID du workflow englobant si cet appel LLM a été déclenché depuis une étape
     * de {@see SynapseWorkflowRun}.
     *
     * NULL = appel hors workflow (chat direct, agent standalone, tâche système).
     * Correspond au champ `workflowRunId` de {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentContext}.
     * Permet de filtrer tous les logs d'un workflow d'une seule requête indexée.
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $workflowRunId = null;

    /**
     * Données du debug (JSON).
     *
     * Contient :
     * - preset_config : paramètres effectivement envoyés au LLM
     * - raw_request_body : body brut de la requête HTTP
     * - history : historique des messages
     * - usage : tokens consommés
     * - safety_ratings : résultats des contrôles de sécurité
     * - turns : détails de chaque tour de conversation
     * - tool_executions : exécutions d'outils (si applicable)
     */
    /**
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $data = [];

    // Getters et Setters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDebugId(): string
    {
        return $this->debugId;
    }

    public function setDebugId(string $debugId): self
    {
        $this->debugId = $debugId;

        return $this;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;

        return $this;
    }

    public function getModule(): ?string
    {
        return $this->module;
    }

    public function setModule(?string $module): self
    {
        $this->module = $module;

        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): self
    {
        $this->action = $action;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function setData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    public function getParentRunId(): ?string
    {
        return $this->parentRunId;
    }

    public function setParentRunId(?string $parentRunId): self
    {
        $this->parentRunId = $parentRunId;

        return $this;
    }

    public function getAgentRunId(): ?string
    {
        return $this->agentRunId;
    }

    public function setAgentRunId(?string $agentRunId): self
    {
        $this->agentRunId = $agentRunId;

        return $this;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): self
    {
        $this->depth = $depth;

        return $this;
    }

    public function getOrigin(): string
    {
        return $this->origin;
    }

    public function setOrigin(string $origin): self
    {
        $this->origin = $origin;

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
}
