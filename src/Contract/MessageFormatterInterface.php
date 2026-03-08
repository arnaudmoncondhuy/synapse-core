<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;

/**
 * Interface responsable du formatage et de la normalisation des échanges.
 *
 * Ce service gère la conversion entre les entités de base de données (SynapseMessage)
 * et le format "OpenAI canonical" utilisé en interne et par les clients LLM.
 */
interface MessageFormatterInterface
{
    /**
     * Convertit une liste d'entités de messages vers le format API brut.
     *
     * @param iterable<object> $entities
     *
     * @return array<int, array<string, mixed>>
     */
    public function entitiesToApiFormat(iterable $entities): array;

    /**
     * Transforme des messages provenant d'une API en objets entités prêts à la persistance.
     *
     * @param array<int, array<string, mixed>> $messages messages au format API
     * @param SynapseConversation $conversation la conversation parente à laquelle lier les messages
     *
     * @return array<int, object> liste des nouvelles entités SynapseMessage (non persistées)
     */
    public function apiFormatToEntities(array $messages, SynapseConversation $conversation): array;
}
