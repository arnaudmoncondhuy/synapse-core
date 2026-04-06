<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance;

/**
 * Verdict immuable rendu par un {@see \ArnaudMoncondhuy\SynapseCore\Contract\PromptJudgeInterface}.
 *
 * Un judgment est **purement informatif** : il n'empêche jamais une sauvegarde,
 * il alimente seulement le signal qualité affiché dans l'admin et exporté pour
 * la surveillance de dérive des prompts (Garde-fou #2).
 *
 * ## Sémantique du score
 *
 * Le score global est une note entre 0.0 et 10.0 :
 *   - 0.0 à 3.9  : le prompt présente des défauts critiques (incohérences, zones grises sécurité, directives contradictoires).
 *   - 4.0 à 6.9  : prompt fonctionnel mais avec des axes d'amélioration identifiés.
 *   - 7.0 à 8.9  : prompt de bonne qualité, quelques optimisations marginales possibles.
 *   - 9.0 à 10.0 : prompt exemplaire.
 *
 * Les seuils et la grille sont indicatifs : ils dépendent de l'implémentation du
 * judge et du modèle LLM sous-jacent. L'admin peut rejeter ou accepter un score
 * sans réclamation.
 *
 * ## Données structurées
 *
 * Le champ `data` permet à un judge de renvoyer un détail arbitraire (scores par
 * critère, points forts / faibles, suggestions d'amélioration, comparaison avec
 * la version précédente). Aucun schéma n'est imposé — la UI admin affiche
 * uniquement `score` et `rationale`, le reste est stocké pour analyse future.
 */
final readonly class PromptJudgment
{
    /**
     * @param float $score Note globale entre 0.0 et 10.0
     * @param string $rationale Résumé textuel (quelques phrases) destiné à l'affichage admin
     * @param array<string, mixed> $data Détail structuré du jugement (schéma libre)
     * @param string $judgedBy Identifiant du juge — ex: `model:gemini/2.5-flash`, `heuristic:length-check`
     */
    public function __construct(
        public float $score,
        public string $rationale,
        public array $data = [],
        public string $judgedBy = 'unknown',
    ) {
    }
}
