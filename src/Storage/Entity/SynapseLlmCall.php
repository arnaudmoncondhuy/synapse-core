<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Enregistrement atomique d'un appel LLM (une requête vers le modèle IA).
 *
 * Table centrale du token accounting. Chaque appel LLM — qu'il provienne du chat,
 * d'une génération de titre, d'une tâche automatisée ou d'un agent — produit une
 * ligne dans cette table.
 *
 * Les tables synapse_conversation et synapse_message sont des agrégateurs :
 * elles référencent synapse_llm_call via llm_call_id pour relier un message
 * à son appel LLM exact (tokens, coût, tarif snapshot).
 *
 * Snapshot immuable des tarifs : cost_model_currency, cost_reference, pricing_*
 * sont figés au moment de l'appel — ils ne seront jamais recalculés avec les
 * tarifs futurs, garantissant l'exactitude de l'historique de coûts.
 */
#[ORM\Entity]
#[ORM\Table(name: 'synapse_llm_call')]
#[ORM\Index(name: 'idx_llm_call_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_llm_call_module_model', columns: ['module', 'model'])]
#[ORM\Index(name: 'idx_llm_call_preset_created', columns: ['preset_id', 'created_at'])]
#[ORM\Index(name: 'idx_llm_call_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_llm_call_mission_created', columns: ['mission_id', 'created_at'])]
#[ORM\Index(name: 'idx_llm_call_conversation_created', columns: ['conversation_id', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class SynapseLlmCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /**
     * Identifiant unique de l'appel (UUID v4).
     *
     * Stocké dans synapse_message.llm_call_id pour relier le message
     * assistant à son appel LLM exact.
     */
    #[ORM\Column(type: Types::STRING, length: 36, unique: true)]
    private string $callId;

    /**
     * Module ou fonctionnalité concernée
     *
     * Exemples : 'chat', 'title_generation', 'gmail', 'calendar', 'summarization'
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $module;

    /**
     * Action spécifique dans le module
     *
     * Exemples : 'chat_turn', 'title_generation', 'email_draft', 'event_suggestion'
     */
    #[ORM\Column(type: Types::STRING, length: 50)]
    private string $action;

    /**
     * Modèle IA utilisé
     *
     * Exemples : 'gemini-2.5-flash', 'gemini-2.0-flash-exp', 'gemini-1.5-pro'
     */
    #[ORM\Column(type: Types::STRING, length: 100)]
    private string $model;

    /**
     * Nombre de tokens dans le prompt (input)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $promptTokens = 0;

    /**
     * Nombre de tokens dans la complétion (output)
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $completionTokens = 0;

    /**
     * Nombre de tokens dans le thinking (Gemini 2.5+)
     */
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $thinkingTokens = 0;

    /**
     * Nombre total de tokens
     */
    #[ORM\Column(type: Types::INTEGER)]
    private int $totalTokens = 0;

    /**
     * ID de l'utilisateur concerné (nullable pour tâches système)
     */
    #[ORM\Column(type: Types::STRING, length: 50, nullable: true)]
    private ?string $userId = null;

    /**
     * ID de la conversation concernée (si applicable)
     */
    #[ORM\Column(type: Types::STRING, length: 36, nullable: true)]
    private ?string $conversationId = null;

    /**
     * ID du preset LLM utilisé (si applicable, pour plafonds par preset)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $presetId = null;

    /**
     * ID de la mission (assistant) utilisée (si applicable, pour plafonds par mission)
     */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $missionId = null;

    /**
     * Date de création
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * Snapshot du coût dans la devise du modèle au moment de la requête (ex: USD)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?float $costModelCurrency = null;

    /**
     * Snapshot du coût converti en devise de référence au moment de la requête (ex: EUR)
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6, nullable: true)]
    private ?float $costReference = null;

    /**
     * Snapshot du tarif input ($/1M tokens) appliqué au moment de la requête
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?float $pricingInput = null;

    /**
     * Snapshot du tarif output ($/1M tokens) appliqué au moment de la requête
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 8, nullable: true)]
    private ?float $pricingOutput = null;

    /**
     * Devise du snapshot de tarif (USD, EUR…)
     */
    #[ORM\Column(type: Types::STRING, length: 3, nullable: true)]
    private ?string $pricingCurrency = null;

    /**
     * Métadonnées libres (JSON) — debug_id, durée, etc.
     * Note : les coûts/tarifs sont dans les colonnes dédiées, pas ici.
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    public function __construct()
    {
        $this->callId = Uuid::v4()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCallId(): string
    {
        return $this->callId;
    }

    public function getModule(): string
    {
        return $this->module;
    }

    public function setModule(string $module): self
    {
        $this->module = $module;
        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function setModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function setPromptTokens(int $promptTokens): self
    {
        $this->promptTokens = $promptTokens;
        return $this;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function setCompletionTokens(int $completionTokens): self
    {
        $this->completionTokens = $completionTokens;
        return $this;
    }

    public function getThinkingTokens(): int
    {
        return $this->thinkingTokens;
    }

    public function setThinkingTokens(int $thinkingTokens): self
    {
        $this->thinkingTokens = $thinkingTokens;
        return $this;
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    public function setTotalTokens(int $totalTokens): self
    {
        $this->totalTokens = $totalTokens;
        return $this;
    }

    public function calculateTotalTokens(): self
    {
        $this->totalTokens = $this->promptTokens + $this->completionTokens + $this->thinkingTokens;
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

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    public function setConversationId(?string $conversationId): self
    {
        $this->conversationId = $conversationId;
        return $this;
    }

    public function getPresetId(): ?int
    {
        return $this->presetId;
    }

    public function setPresetId(?int $presetId): self
    {
        $this->presetId = $presetId;
        return $this;
    }

    public function getMissionId(): ?int
    {
        return $this->missionId;
    }

    public function setMissionId(?int $missionId): self
    {
        $this->missionId = $missionId;
        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return $this->metadata[$key] ?? $default;
    }

    public function setMetadataValue(string $key, mixed $value): self
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getCostModelCurrency(): ?float
    {
        return $this->costModelCurrency !== null ? (float) $this->costModelCurrency : null;
    }

    public function setCostModelCurrency(?float $costModelCurrency): self
    {
        $this->costModelCurrency = $costModelCurrency;
        return $this;
    }

    public function getCostReference(): ?float
    {
        return $this->costReference !== null ? (float) $this->costReference : null;
    }

    public function setCostReference(?float $costReference): self
    {
        $this->costReference = $costReference;
        return $this;
    }

    public function getPricingInput(): ?float
    {
        return $this->pricingInput !== null ? (float) $this->pricingInput : null;
    }

    public function setPricingInput(?float $pricingInput): self
    {
        $this->pricingInput = $pricingInput;
        return $this;
    }

    public function getPricingOutput(): ?float
    {
        return $this->pricingOutput !== null ? (float) $this->pricingOutput : null;
    }

    public function setPricingOutput(?float $pricingOutput): self
    {
        $this->pricingOutput = $pricingOutput;
        return $this;
    }

    public function getPricingCurrency(): ?string
    {
        return $this->pricingCurrency;
    }

    public function setPricingCurrency(?string $pricingCurrency): self
    {
        $this->pricingCurrency = $pricingCurrency;
        return $this;
    }
}
