<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

/**
 * Résultat d'un appel d'agent.
 *
 * VO immutable retourné par {@see AgentInterface::call()}.
 *
 * Nom de classe volontairement aligné sur `Symfony\AI\Agent\Output` pour garder
 * un vocabulaire cohérent avec l'écosystème Symfony AI. Voir {@see Input} pour
 * plus de détails sur cet alignement terminologique.
 *
 * @author Synapse Bundle
 */
final class Output
{
    /**
     * @param string|null $answer Texte de réponse principal (null si l'agent ne produit qu'un résultat structuré)
     * @param array<string, mixed> $data Données structurées produites par l'agent (rapports, décisions, etc.)
     * @param array<string, mixed> $usage Consommation tokens (prompt_tokens, completion_tokens, total_tokens, ...)
     * @param string|null $debugId Identifiant du SynapseDebugLog créé pendant l'exécution, si debug activé
     * @param array<int, array<string, mixed>> $toolCalls Appels d'outils effectués par l'agent
     * @param array<int, array<string, mixed>> $generatedAttachments Pièces jointes générées par l'agent (images, fichiers)
     * @param array<string, mixed> $metadata Métadonnées libres (modèle utilisé, preset, durée, etc.)
     */
    public function __construct(
        private readonly ?string $answer = null,
        private readonly array $data = [],
        private readonly array $usage = [],
        private readonly ?string $debugId = null,
        private readonly array $toolCalls = [],
        private readonly array $generatedAttachments = [],
        private readonly array $metadata = [],
    ) {
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUsage(): array
    {
        return $this->usage;
    }

    public function getDebugId(): ?string
    {
        return $this->debugId;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getGeneratedAttachments(): array
    {
        return $this->generatedAttachments;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Crée une sortie à partir du tableau retourné par {@see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService::ask()}.
     *
     * @param array<string, mixed> $result
     */
    public static function fromChatServiceResult(array $result): self
    {
        /** @var string|null $answer */
        $answer = isset($result['answer']) && is_string($result['answer']) ? $result['answer'] : null;

        /** @var string|null $debugId */
        $debugId = isset($result['debug_id']) && is_string($result['debug_id']) ? $result['debug_id'] : null;

        /** @var array<string, mixed> $usage */
        $usage = isset($result['usage']) && is_array($result['usage']) ? $result['usage'] : [];

        /** @var array<int, array<string, mixed>> $generated */
        $generated = isset($result['generated_attachments']) && is_array($result['generated_attachments'])
            ? $result['generated_attachments']
            : [];

        // Structured output : peuplé quand l'appelant a fourni response_format.
        /** @var array<string, mixed> $data */
        $data = isset($result['structured_output']) && is_array($result['structured_output'])
            ? $result['structured_output']
            : [];

        $metadata = [];
        foreach (['model', 'preset_id', 'agent_id', 'safety'] as $key) {
            if (array_key_exists($key, $result)) {
                $metadata[$key] = $result[$key];
            }
        }

        return new self(
            answer: $answer,
            data: $data,
            usage: $usage,
            debugId: $debugId,
            generatedAttachments: $generated,
            metadata: $metadata,
        );
    }

    /**
     * Crée une sortie purement structurée (agent non-conversationnel).
     *
     * @param array<string, mixed> $data
     */
    public static function ofData(array $data): self
    {
        return new self(data: $data);
    }
}
