<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance\PresetArchitect;

use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ModelRange;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ModelCapabilities;

/**
 * Recommandeur déterministe de preset — utilisé en first-boot
 * quand aucun LLM n'est encore disponible.
 *
 * Heuristique : préfère balanced, puis flagship, puis fast.
 * À range égal, choisit le moins cher.
 */
final class HeuristicRecommender
{
    /**
     * @param list<array{modelId: string, provider: string, providerLabel: string, capabilities: ModelCapabilities}> $candidates
     * @param ModelRange|null $preferredRange Range préféré (le range préféré passe en priorité 0, les autres gardent leur priorité par défaut)
     */
    public function recommend(array $candidates, ?ModelRange $preferredRange = null): PresetRecommendation
    {
        if ([] === $candidates) {
            throw new \InvalidArgumentException('Aucun modèle candidat disponible pour générer un preset.');
        }

        // Trier par : RGPD (null=EU d'abord), range préféré, puis coût.
        usort($candidates, function (array $a, array $b) use ($preferredRange): int {
            // Les providers EU (rgpdRisk=null) passent en premier
            $rgpdA = $this->rgpdSortPriority($a['capabilities']->rgpdRisk);
            $rgpdB = $this->rgpdSortPriority($b['capabilities']->rgpdRisk);
            if ($rgpdA !== $rgpdB) {
                return $rgpdA <=> $rgpdB;
            }

            $priorityA = $this->effectivePriority($a['capabilities']->range, $preferredRange);
            $priorityB = $this->effectivePriority($b['capabilities']->range, $preferredRange);
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            $costA = ($a['capabilities']->pricingInput ?? 0.0) + ($a['capabilities']->pricingOutput ?? 0.0);
            $costB = ($b['capabilities']->pricingInput ?? 0.0) + ($b['capabilities']->pricingOutput ?? 0.0);

            return $costA <=> $costB;
        });

        $best = $candidates[0];
        /** @var ModelCapabilities $caps */
        $caps = $best['capabilities'];

        $temperature = $caps->range->defaultTemperature();
        $topK = $caps->supportsTopK ? 40 : null;
        $streaming = $caps->supportsStreaming;

        // Thinking : activer si supporté, budget à 25% du max output
        $providerOptions = null;
        if ($caps->supportsThinking && null !== $caps->maxOutputTokens && $caps->maxOutputTokens > 0) {
            $thinkingBudget = (int) round($caps->maxOutputTokens * 0.25);
            $providerOptions = [
                'thinking' => [
                    'type' => 'enabled',
                    'budget_tokens' => $thinkingBudget,
                ],
            ];
        }

        $suggestedName = sprintf('%s — %s (%s)', $best['providerLabel'], $best['modelId'], $caps->range->label());
        $suggestedKey = $this->slugify($best['provider'].'_'.$best['modelId']);

        $justification = $this->buildJustification($best, $candidates);

        return new PresetRecommendation(
            provider: $best['provider'],
            model: $best['modelId'],
            suggestedName: $suggestedName,
            suggestedKey: $suggestedKey,
            temperature: $temperature,
            topP: 0.95,
            topK: $topK,
            maxOutputTokens: null,
            streamingEnabled: $streaming,
            providerOptions: $providerOptions,
            range: $caps->range,
            rgpdRisk: $caps->rgpdRisk,
            justification: $justification,
            llmAssisted: false,
        );
    }

    /**
     * @param array{modelId: string, provider: string, providerLabel: string, capabilities: ModelCapabilities} $best
     * @param list<array{modelId: string, provider: string, providerLabel: string, capabilities: ModelCapabilities}> $allCandidates
     */
    private function buildJustification(array $best, array $allCandidates): string
    {
        /** @var ModelCapabilities $caps */
        $caps = $best['capabilities'];
        $totalCandidates = \count($allCandidates);
        $cost = ($caps->pricingInput ?? 0.0) + ($caps->pricingOutput ?? 0.0);

        $lines = [];
        $lines[] = sprintf(
            'Sélection automatique parmi %d modèle(s) candidat(s).',
            $totalCandidates,
        );
        $lines[] = sprintf(
            'Modèle choisi : %s (%s) — gamme %s.',
            $best['modelId'],
            $best['providerLabel'],
            $caps->range->label(),
        );

        if ($cost > 0) {
            $lines[] = sprintf(
                'Coût : %.2f %s / M tokens (input + output).',
                $cost,
                $caps->currency,
            );
        }

        $features = [];
        if ($caps->supportsThinking) {
            $features[] = 'thinking';
        }
        if ($caps->supportsVision) {
            $features[] = 'vision';
        }
        if ($caps->supportsFunctionCalling) {
            $features[] = 'tools';
        }
        if ($caps->supportsResponseSchema) {
            $features[] = 'structured output';
        }
        if ([] !== $features) {
            $lines[] = sprintf('Capacités notables : %s.', implode(', ', $features));
        }

        if (null === $caps->rgpdRisk) {
            $lines[] = 'RGPD : provider européen, pas de Cloud Act — adapté aux données sensibles.';
        } elseif ('tolerated' === $caps->rgpdRisk) {
            $lines[] = 'RGPD : provider US (Cloud Act applicable), régions UE disponibles. Acceptable sous conditions (DPA), mais risque juridique résiduel pour les données sensibles.';
        } elseif ('risk' === $caps->rgpdRisk) {
            $lines[] = 'RGPD : provider US (Cloud Act), hébergement hors UE — déconseillé pour les données personnelles sensibles.';
        } elseif ('danger' === $caps->rgpdRisk) {
            $lines[] = 'RGPD : INTERDIT pour les données personnelles (modèle entraîné sur les données utilisateurs).';
        }

        return implode("\n", $lines);
    }

    private function rgpdSortPriority(?string $rgpdRisk): int
    {
        return match ($rgpdRisk) {
            null => 0,         // EU — le plus sûr
            'tolerated' => 1,  // US + régions UE
            'risk' => 2,       // US hors UE
            'danger' => 99,    // interdit
            default => 50,
        };
    }

    private function effectivePriority(ModelRange $range, ?ModelRange $preferred): int
    {
        if (null !== $preferred && $range === $preferred) {
            return -1; // Le range préféré passe devant tout
        }

        return $range->sortPriority();
    }

    private function slugify(string $text): string
    {
        $text = strtolower($text);
        $text = (string) preg_replace('/[^a-z0-9]+/', '_', $text);

        return trim($text, '_');
    }
}
