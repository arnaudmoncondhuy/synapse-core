<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Service;

/**
 * Porte le contexte de la conversation en cours dans le scope de la requête.
 *
 * Ce service est injecté dans les tools qui ont besoin de connaître la conversation
 * active (ex: CodeExecuteTool pour pré-stager les fichiers uploadés dans le sandbox).
 * Il est alimenté par ChatService au début de ask() et réinitialisé après.
 *
 * Les attachments bruts du message courant sont stockés ici car ils ne sont
 * persistés en base qu'APRÈS ask() — les tools ne peuvent donc pas les lire en DB.
 */
class ConversationContextHolder
{
    private ?string $conversationId = null;

    /** @var list<array{mime_type: string, data: string, name?: string}> */
    private array $currentAttachments = [];

    /** @var list<array{mime_type: string, data: string, name?: string}> Artefacts générés par les tools (code_execute, etc.) */
    private array $generatedArtifacts = [];

    public function set(string $conversationId): void
    {
        $this->conversationId = $conversationId;
    }

    public function getConversationId(): ?string
    {
        return $this->conversationId;
    }

    /**
     * @param list<array{mime_type: string, data: string, name?: string}> $attachments
     */
    public function setAttachments(array $attachments): void
    {
        $this->currentAttachments = $attachments;
    }

    /**
     * @return list<array{mime_type: string, data: string, name?: string}>
     */
    public function getAttachments(): array
    {
        return $this->currentAttachments;
    }

    /**
     * @param list<array{mime_type: string, data: string, name?: string}> $artifacts
     */
    public function addGeneratedArtifacts(array $artifacts): void
    {
        foreach ($artifacts as $artifact) {
            $this->generatedArtifacts[] = $artifact;
        }
    }

    /**
     * @return list<array{mime_type: string, data: string, name?: string}>
     */
    public function getGeneratedArtifacts(): array
    {
        return $this->generatedArtifacts;
    }

    public function clear(): void
    {
        $this->conversationId = null;
        $this->currentAttachments = [];
        $this->generatedArtifacts = [];
    }
}
