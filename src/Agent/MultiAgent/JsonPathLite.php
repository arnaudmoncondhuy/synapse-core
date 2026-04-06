<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent;

/**
 * Mini-évaluateur JSONPath utilisé par le moteur {@see MultiAgent} pour résoudre
 * les expressions `input_mapping` / `outputs` du format pivot de workflow.
 *
 * **Grammaire supportée — volontairement minimale** :
 * - `$.inputs.KEY` — lit l'input initial du workflow.
 * - `$.inputs.KEY.SUBKEY.…` — descente en profondeur dans les inputs.
 * - `$.steps.STEPNAME.output.KEY.…` — lit l'output d'un step précédent.
 *
 * **Non supporté** : wildcards (`*`), filtres (`[?(@...)]`), slicing (`[0:2]`),
 * récursion descendante (`..`), indexation numérique de tableaux. Ces fonctionnalités
 * viendront si un cas d'usage les justifie — Phase 8 couvre le besoin minimal
 * d'un workflow séquentiel déterministe.
 *
 * La méthode retourne `null` plutôt qu'une exception si le path ne résout rien,
 * pour permettre au moteur de traiter les inputs optionnels avec un `?? defaultValue`
 * côté appelant. Les erreurs de grammaire (path mal formé) lèvent en revanche
 * une {@see \InvalidArgumentException} — c'est un bug de la définition du workflow.
 */
final class JsonPathLite
{
    /**
     * Évalue un chemin JSONPath-lite contre un state de workflow.
     *
     * @param array<string, mixed> $state State au format `{inputs: {...}, steps: {NAME: {output: {...}}, …}}`
     * @param string $path Expression JSONPath-lite (doit commencer par `$.`)
     *
     * @throws \InvalidArgumentException si la grammaire du path est invalide
     *
     * @return mixed La valeur résolue, ou `null` si un segment est manquant
     */
    public static function evaluate(array $state, string $path): mixed
    {
        if (!str_starts_with($path, '$.')) {
            throw new \InvalidArgumentException(sprintf('JSONPath expression must start with "$.", got "%s".', $path));
        }

        $segments = explode('.', substr($path, 2));
        if ([] === $segments) {
            throw new \InvalidArgumentException(sprintf('JSONPath expression "%s" has no segments after root.', $path));
        }

        // Valider la grammaire en premier : aucun segment ne peut être vide (détecte
        // `$.`, `$..x`, `$.a..b`, etc.). Une fois la forme validée, on résout contre
        // le state en retournant null si un segment manque.
        foreach ($segments as $segment) {
            if ('' === $segment) {
                throw new \InvalidArgumentException(sprintf('JSONPath expression "%s" contains an empty segment.', $path));
            }
        }

        $current = $state;
        foreach ($segments as $segment) {
            if (!is_array($current)) {
                return null;
            }
            if (!array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Détecte si une expression est un JSONPath-lite (commence par `$.`).
     * Utilisé par le moteur pour différencier une expression dynamique d'une valeur littérale.
     */
    public static function isExpression(string $value): bool
    {
        return str_starts_with($value, '$.');
    }
}
