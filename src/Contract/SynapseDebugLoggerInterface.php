<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour l'enregistrement détaillé des échanges LLM à des fins de debug.
 *
 * Les implémentations peuvent stocker les payloads bruts (requêtes/réponses) dans
 * différents backends pour permettre l'analyse des erreurs et de la qualité.
 */
interface SynapseDebugLoggerInterface
{
    /**
     * Enregistre un échange complet avec ses métadonnées et son payload brut.
     *
     * @param string               $debugId    ID unique de l'échange (ex: dbg_...).
     * @param array<string, mixed> $metadata   Données légères (modèle, tokens, durée).
     * @param array<string, mixed> $rawPayload Corps complet de la requête et de la réponse API.
     */
    public function logExchange(string $debugId, array $metadata, array $rawPayload): void;

    /**
     * Récupère un enregistrement de debug complet par son ID.
     *
     * @return array<string, mixed>|null Les données enregistrées ou null si inexistant.
     */
    public function findByDebugId(string $debugId): ?array;
}
