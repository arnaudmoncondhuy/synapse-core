<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Résultat de l'évaluation RGPD d'un provider LLM pour une configuration donnée.
 *
 * Niveaux de conformité (du plus au moins favorable) :
 *   - compliant  : hébergeur et société européens, données en UE
 *   - tolerated  : société non-UE mais données hébergées en UE, DPA signé, pas d'entraînement
 *                  — toléré par la CNIL sous conditions
 *   - risk       : pays tiers avec protection adéquate reconnue
 *   - danger     : données hors UE sans garanties suffisantes, ou utilisées pour l'entraînement
 *   - unknown    : configuration incomplète, statut impossible à déterminer
 */
final class RgpdInfo
{
    public function __construct(
        /** 'compliant' | 'tolerated' | 'risk' | 'danger' | 'unknown' */
        public readonly string $status,

        /** Résumé court affiché dans le badge (ex: "Provider EU") */
        public readonly string $label,

        /** Explication affichée en tooltip ou dans le détail */
        public readonly string $explanation,
    ) {
    }

    public function isCompliant(): bool
    {
        return 'compliant' === $this->status;
    }

    public function isTolerated(): bool
    {
        return 'tolerated' === $this->status;
    }

    public function isAtRisk(): bool
    {
        return in_array($this->status, ['risk', 'danger'], true);
    }

    public function isWarning(): bool
    {
        return in_array($this->status, ['tolerated', 'risk', 'danger', 'unknown'], true);
    }
}
