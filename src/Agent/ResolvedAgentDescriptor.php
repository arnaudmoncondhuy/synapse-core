<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;

/**
 * VO immutable normalisant les propriétés d'un agent pour le pipeline.
 *
 * Permet à {@see \ArnaudMoncondhuy\SynapseCore\Event\ContextBuilderSubscriber} de traiter
 * de manière uniforme un agent DB ({@see SynapseAgent}) et un agent code
 * ({@see AbstractAgent}) : même system prompt injection, même tracking, même coûts.
 *
 * ## Sources
 *
 * - `fromEntity()` : agent configuré en base (admin UI, MCP sandbox)
 * - `fromCodeAgent()` : agent défini en code PHP (bundle ou app hôte)
 *
 * ## Convention : system prompt vide
 *
 * Un `systemPrompt` vide (`''`) signifie que l'agent gère son propre prompt
 * (mode orchestrateur). Le pipeline ne remplace pas le prompt global dans ce cas.
 */
final class ResolvedAgentDescriptor
{
    /**
     * @param list<string> $allowedToolNames
     */
    public function __construct(
        public readonly string $name,
        public readonly ?int $id,
        public readonly string $systemPrompt,
        public readonly array $allowedToolNames,
        public readonly ?string $presetKey,
        public readonly ?string $toneKey,
        public readonly string $emoji,
        public readonly string $source,
    ) {
    }

    /**
     * Construit un descripteur depuis une entité SynapseAgent (DB).
     */
    public static function fromEntity(SynapseAgent $entity): self
    {
        return new self(
            name: $entity->getKey(),
            id: $entity->getId(),
            systemPrompt: $entity->getSystemPrompt(),
            allowedToolNames: array_values($entity->getAllowedToolNames()),
            presetKey: $entity->getModelPreset()?->getKey(),
            toneKey: $entity->getTone()?->getKey(),
            emoji: $entity->getEmoji(),
            source: 'db',
        );
    }

    /**
     * Construit un descripteur depuis un agent code.
     *
     * Si l'agent étend {@see AbstractAgent}, ses méthodes pipeline
     * (`getSystemPrompt()`, `getAllowedToolNames()`, etc.) sont utilisées.
     * Sinon (implémentation brute d'{@see AgentInterface}), seul le nom
     * est extrait — pas d'injection de prompt, tracking minimal.
     */
    public static function fromCodeAgent(AgentInterface $agent): self
    {
        if ($agent instanceof AbstractAgent) {
            return new self(
                name: $agent->getName(),
                id: null,
                systemPrompt: $agent->getSystemPrompt(),
                allowedToolNames: $agent->getAllowedToolNames(),
                presetKey: $agent->getPresetKey(),
                toneKey: $agent->getToneKey(),
                emoji: $agent->getEmoji(),
                source: 'code',
            );
        }

        // Agent brut (AgentInterface sans AbstractAgent) : tracking minimal
        return new self(
            name: $agent->getName(),
            id: null,
            systemPrompt: '',
            allowedToolNames: [],
            presetKey: null,
            toneKey: null,
            emoji: "\u{1F916}",
            source: 'code',
        );
    }
}
