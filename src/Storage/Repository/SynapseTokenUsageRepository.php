<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Storage\Repository;

/**
 * Alias de compatibilité — l'ancienne classe TokenUsageRepository a été renommée en SynapseLlmCallRepository.
 *
 * Cette classe existe uniquement pour la compatibilité ascendante. Tous les nouveaux code doivent utiliser SynapseLlmCallRepository.
 *
 * @deprecated Use SynapseLlmCallRepository instead
 */
final class SynapseTokenUsageRepository extends SynapseLlmCallRepository
{
    // Héritage complet de SynapseLlmCallRepository pour la compatibilité
}
