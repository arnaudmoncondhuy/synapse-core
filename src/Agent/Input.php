<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

/**
 * Entrée d'un appel d'agent.
 *
 * VO immutable transporté à {@see AgentInterface::call()}.
 *
 * Nom de classe volontairement aligné sur `Symfony\AI\Agent\Input` pour garder
 * un vocabulaire cohérent avec l'écosystème Symfony AI, même si aucune migration
 * vers `symfony/ai` n'est prévue à court ou moyen terme. C'est un choix de
 * nomenclature, pas un chemin d'intégration.
 *
 * @author Synapse Bundle
 */
final class Input
{
    /**
     * @param string $message Message utilisateur principal (chaîne simple)
     * @param list<array{mime_type: string, data: string}> $attachments Pièces jointes (images, fichiers) transmises au LLM,
     *                                                                  au format attendu par {@see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService::ask()}
     * @param array<string, mixed> $structured Entrée structurée optionnelle pour les agents
     *                                         non-conversationnels (ex: PresetValidatorAgent).
     *                                         Doit rester purement scalaire/tableau pour permettre
     *                                         une sérialisation Messenger ultérieure.
     */
    public function __construct(
        private readonly string $message = '',
        private readonly array $attachments = [],
        private readonly array $structured = [],
    ) {
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return list<array{mime_type: string, data: string}>
     */
    public function getAttachments(): array
    {
        return $this->attachments;
    }

    /**
     * @return array<string, mixed>
     */
    public function getStructured(): array
    {
        return $this->structured;
    }

    /**
     * Crée une entrée à partir d'un simple message texte.
     */
    public static function ofMessage(string $message): self
    {
        return new self(message: $message);
    }

    /**
     * Crée une entrée structurée (sans message texte), pour les agents non-conversationnels.
     *
     * @param array<string, mixed> $data
     */
    public static function ofStructured(array $data): self
    {
        return new self(structured: $data);
    }
}
