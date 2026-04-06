<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Trait\TimestampableEntityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseWorkflowRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Mapping as ORM;

/**
 * Définition d'un workflow Synapse — une suite ordonnée d'étapes exécutées par
 * des agents {@see \ArnaudMoncondhuy\SynapseCore\Agent\AgentInterface}.
 *
 * **Immutable par design** : toute modification du champ `definition` incrémente
 * automatiquement `version`. Les exécutions ({@see SynapseWorkflowRun}) stockent
 * la version effective au moment du run, garantissant la traçabilité historique
 * même si la définition évolue par la suite.
 *
 * Phase 7 : stockage et admin CRUD uniquement. Aucun moteur d'exécution n'est
 * encore câblé — Phase 8 introduira la classe `MultiAgent` qui consommera ces
 * définitions. Une entité `SynapseWorkflow` peut donc exister sans jamais avoir
 * été exécutée.
 *
 * Format pivot de `$definition` (documenté dans le plan Phase 7) :
 * ```
 * {
 *   "version": 1,
 *   "description": "...",
 *   "inputs": { "key": { "type": "string", "required": true } },
 *   "steps": [
 *     { "name": "step1", "agent_name": "MyAgent", "input_mapping": {...}, "output_key": "result" }
 *   ],
 *   "outputs": { "final": "$.steps.step1.output.text" }
 * }
 * ```
 */
#[ORM\Entity(repositoryClass: SynapseWorkflowRepository::class)]
#[ORM\Table(name: 'synapse_workflow')]
#[ORM\HasLifecycleCallbacks]
class SynapseWorkflow
{
    use TimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Clé unique (slug). Référencée par le futur moteur `MultiAgent` Phase 8.
     */
    #[ORM\Column(name: 'workflow_key', type: Types::STRING, length: 100, unique: true)]
    private string $workflowKey = '';

    /**
     * Nom lisible affiché dans l'admin.
     */
    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name = '';

    /**
     * Description longue optionnelle.
     */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Définition du workflow au format pivot (voir docblock de classe).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $definition = ['version' => 1, 'steps' => []];

    /**
     * Version de la définition, incrémentée automatiquement via `#[ORM\PreUpdate]`
     * dès qu'un changement est détecté sur le champ `definition`.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    /**
     * Workflow visible dans les sélecteurs et appelable par le moteur.
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    /**
     * Workflow fourni par le bundle (ne peut pas être supprimé depuis l'admin).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isBuiltin = false;

    /**
     * Entité temporaire créée via MCP pour des tests autonomes (sandbox).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $isSandbox = false;

    /**
     * Ordre d'affichage dans les listes (plus petit = affiché en premier).
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Incrémente automatiquement `version` si `definition` a été modifiée.
     *
     * Cohabite avec {@see TimestampableEntityTrait::updateTimestamp()} : Doctrine
     * exécute tous les hooks `#[ORM\PreUpdate]` déclarés sur l'entité.
     */
    #[ORM\PreUpdate]
    public function bumpVersionIfDefinitionChanged(PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField('definition')) {
            ++$this->version;
        }
    }

    // ── Getters / Setters ────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function setDefinition(array $definition): self
    {
        $this->definition = $definition;

        return $this;
    }

    public function getVersion(): int
    {
        return $this->version;
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

    public function isBuiltin(): bool
    {
        return $this->isBuiltin;
    }

    public function setIsBuiltin(bool $isBuiltin): self
    {
        $this->isBuiltin = $isBuiltin;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isSandbox(): bool
    {
        return $this->isSandbox;
    }

    public function setIsSandbox(bool $isSandbox): self
    {
        $this->isSandbox = $isSandbox;

        return $this;
    }

    /**
     * Nombre de steps déclarés dans la définition (lecture rapide sans déchiffrer le JSON).
     */
    public function getStepsCount(): int
    {
        $steps = $this->definition['steps'] ?? [];

        return is_array($steps) ? count($steps) : 0;
    }
}
