<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Agent;

use ArnaudMoncondhuy\SynapseCore\Core\Chat\ChatService;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;

/**
 * Service de fabrication d'agents IA (AgentBuilder).
 * 
 * Permet de définir dynamiquement le modèle, le prompt système, la température, 
 * et les outils autorisés, tout en validant ces choix via ModelCapabilityRegistry.
 */
class SynapseAgentBuilder
{
    private ?string $model = null;
    private ?string $systemPrompt = null;
    private float $temperature = 1.0;
    private ?float $reasoningBudget = null;
    private ?string $reasoningEffort = null;
    private array $allowedTools = [];
    private int $maxTurns = 5;

    public function __construct(
        private ChatService $chatService,
        private ModelCapabilityRegistry $capabilityRegistry
    ) {}

    /**
     * Définit le modèle LLM à utiliser.
     */
    public function withModel(string $model): self
    {
        $this->model = $model;
        return $this;
    }

    /**
     * Définit le message système (instruction de base).
     */
    public function withSystemPrompt(string $prompt): self
    {
        $this->systemPrompt = $prompt;
        return $this;
    }

    /**
     * Définit la température de génération (0.0 - 2.0).
     */
    public function withTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    /**
     * Définit le budget ou l'effort de réflexion (si supporté par le modèle).
     */
    public function withReasoning(float|string|null $effortOrBudget): self
    {
        if (is_float($effortOrBudget)) {
            $this->reasoningBudget = $effortOrBudget;
        } elseif (is_string($effortOrBudget)) {
            $this->reasoningEffort = $effortOrBudget;
        }
        return $this;
    }

    /**
     * Définit les outils (functions) que l'agent est autorisé à appeler.
     * @param string[] $toolNames
     */
    public function withAllowedTools(array $toolNames): self
    {
        $this->allowedTools = $toolNames;
        return $this;
    }

    /**
     * Définit le nombre maximum de tours outils/LLM autorisés par requête.
     */
    public function withMaxTurns(int $maxTurns): self
    {
        $this->maxTurns = $maxTurns;
        return $this;
    }

    /**
     * Construit l'instance de SynapseAgent configurée.
     * 
     * @throws \LogicException Si le modèle n'est pas défini ou si les capacités requises manquent.
     */
    public function build(): SynapseAgent
    {
        if (!$this->model) {
            throw new \LogicException("Un modèle doit être défini pour construire un agent.");
        }

        $capabilities = $this->capabilityRegistry->getCapabilities($this->model);

        // Validation des capacités critiques
        if (!empty($this->allowedTools) && !$capabilities->functionCalling) {
            throw new \LogicException("Le modèle '{$this->model}' ne supporte pas l'appel d'outils (function_calling).");
        }

        if ($this->systemPrompt && !$capabilities->systemPrompt) {
            // Logique de fallback ou exception ? On choisit l'exception pour garantir la sécurité de Cortex
            throw new \LogicException("Le modèle '{$this->model}' ne supporte pas les instructions système.");
        }

        // Création du Preset Virtuel (Stateless)
        $preset = new SynapsePreset();
        $preset->setName("Agent Virtuel: " . $this->model);
        $preset->setProviderName($capabilities->provider);
        $preset->setModel($this->model);
        $preset->setGenerationTemperature($this->temperature);

        $providerOptions = [];
        if (($this->reasoningBudget || $this->reasoningEffort) && $capabilities->thinking) {
            if ($this->reasoningBudget) $providerOptions['thinking_budget'] = $this->reasoningBudget;
            if ($this->reasoningEffort) $providerOptions['reasoning_effort'] = $this->reasoningEffort;
        }

        $preset->setProviderOptions($providerOptions);

        return new SynapseAgent(
            $this->chatService,
            $preset,
            $this->systemPrompt,
            $this->allowedTools,
            $this->maxTurns
        );
    }
}
