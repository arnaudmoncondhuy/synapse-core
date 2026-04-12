<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Enum;

/**
 * Positionnement d'un modèle LLM dans la gamme de son provider.
 *
 * Utilisé par le {@see \ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect\HeuristicRecommender}
 * pour choisir un modèle adapté lors de la génération automatique de preset,
 * et par l'admin pour afficher le positionnement d'un modèle.
 */
enum ModelRange: string
{
    /** Modèle le plus capable du provider (raisonnement complexe, agents, tâches exigeantes) */
    case FLAGSHIP = 'flagship';

    /** Bon compromis qualité/prix — choix recommandé pour un usage quotidien */
    case BALANCED = 'balanced';

    /** Rapide et économique — adapté aux tâches simples et au volume */
    case FAST = 'fast';

    /** Mono-usage : embedding, génération d'image — ne peut pas servir de preset chat */
    case SPECIALIZED = 'specialized';

    /**
     * Libellé lisible pour l'UI admin.
     */
    public function label(): string
    {
        return match ($this) {
            self::FLAGSHIP => 'Haut de gamme',
            self::BALANCED => 'Équilibré',
            self::FAST => 'Rapide',
            self::SPECIALIZED => 'Spécialisé',
        };
    }

    /**
     * Priorité de tri pour l'heuristique de sélection (plus bas = préféré).
     */
    public function sortPriority(): int
    {
        return match ($this) {
            self::BALANCED => 0,
            self::FLAGSHIP => 1,
            self::FAST => 2,
            self::SPECIALIZED => 99,
        };
    }

    /**
     * Température par défaut recommandée pour cette gamme.
     */
    public function defaultTemperature(): float
    {
        return match ($this) {
            self::FLAGSHIP => 0.7,
            self::BALANCED => 0.8,
            self::FAST => 1.0,
            self::SPECIALIZED => 1.0,
        };
    }

    /**
     * Construit une instance depuis une chaîne YAML, avec fallback sur BALANCED.
     */
    public static function fromString(?string $value): self
    {
        if (null === $value || '' === $value) {
            return self::BALANCED;
        }

        return self::tryFrom($value) ?? self::BALANCED;
    }
}
