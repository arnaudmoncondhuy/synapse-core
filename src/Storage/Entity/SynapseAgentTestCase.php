<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\Trait\TimestampableEntityTrait;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentTestCaseRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Cas de test reproductible attaché à un {@see SynapseAgent} — Garde-fou #4.
 *
 * Voir `.evolutions/CRITICAL_GUARDRAILS.md`.
 *
 * ## Pourquoi ?
 *
 * `run_agent_test` (outil MCP) et l'admin exécutent un agent de manière **ad-hoc**.
 * Pour détecter une régression après modification du prompt (Garde-fou #1) il faut
 * une **baseline reproductible** : mêmes inputs, mêmes critères d'évaluation, à
 * rejouer à la demande.
 *
 * Un test case décrit donc :
 * - Un **input fixe** : un message utilisateur OU un payload structuré.
 * - Un ensemble d'**assertions légères** : sous-chaînes attendues, sous-chaînes
 *   interdites, longueur maximale. Des critères objectifs, rapides à valider
 *   sans nouvel appel LLM (c'est le Garde-fou #2 — LLM-as-Judge — qui ajoutera
 *   un scoring sémantique par-dessus plus tard).
 *
 * ## Format du champ `assertions`
 *
 * ```json
 * {
 *   "contains": ["mot de passe", "réinitialisation"],
 *   "not_contains": ["je ne sais pas"],
 *   "max_tokens": 200,
 *   "min_length": 20
 * }
 * ```
 *
 * Toutes les clés sont optionnelles. La commande `synapse:agent:test-suite`
 * applique chaque assertion présente et ignore silencieusement celles absentes.
 * Format volontairement simple : extensible via nouvelles clés sans migration.
 *
 * ## Relation à l'agent
 *
 * `cascade: [remove]` **n'est pas** appliqué : un test case survit à la
 * suppression de son agent parent grâce à `agentKey` dénormalisé. L'admin
 * présentera alors les cas orphelins comme « à ré-attacher ou supprimer ».
 */
#[ORM\Entity(repositoryClass: SynapseAgentTestCaseRepository::class)]
#[ORM\Table(name: 'synapse_agent_test_case')]
#[ORM\Index(name: 'idx_agent_test_case_agent', columns: ['agent_id'])]
#[ORM\Index(name: 'idx_agent_test_case_agent_key', columns: ['agent_key'])]
#[ORM\HasLifecycleCallbacks]
class SynapseAgentTestCase
{
    use TimestampableEntityTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: SynapseAgent::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?SynapseAgent $agent = null;

    /**
     * Clé de l'agent dénormalisée au moment de la création du test case pour
     * survivre à la suppression de l'agent parent.
     */
    #[ORM\Column(name: 'agent_key', type: Types::STRING, length: 50)]
    private string $agentKey = '';

    /**
     * Nom lisible du test case ("mot de passe oublié", "agression verbale",
     * "question hors sujet", …). Unique dans le scope d'un agent mais pas
     * contraint par index BDD (évite de bloquer un rename trivial).
     */
    #[ORM\Column(type: Types::STRING, length: 150)]
    private string $name = '';

    /**
     * Message utilisateur du test case. Utilisé tel-quel si `structuredInput`
     * est vide ; sinon ignoré (aligné sur `MultiAgent::buildInitialInputs()`).
     */
    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    /**
     * Payload structuré optionnel. Prend le pas sur `message` si non-vide.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $structuredInput = [];

    /**
     * Assertions à vérifier sur la sortie de l'agent. Format documenté dans le
     * phpdoc de classe.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $assertions = [];

    /**
     * Ordre d'exécution dans le lot. Les plus petits passent en premier.
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $sortOrder = 0;

    /**
     * Désactivation manuelle (ex : test flaky en quarantaine).
     */
    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): self
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStructuredInput(): array
    {
        return $this->structuredInput;
    }

    /**
     * @param array<string, mixed> $structuredInput
     */
    public function setStructuredInput(array $structuredInput): self
    {
        $this->structuredInput = $structuredInput;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAssertions(): array
    {
        return $this->assertions;
    }

    /**
     * @param array<string, mixed> $assertions
     */
    public function setAssertions(array $assertions): self
    {
        $this->assertions = $assertions;

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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
