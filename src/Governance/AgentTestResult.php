<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase;

/**
 * Résultat d'exécution d'un {@see SynapseAgentTestCase} par {@see AgentTestRunner}.
 *
 * VO immuable. Porte l'issue (succès / échec / erreur d'exécution) + la liste
 * des assertions individuelles avec leur verdict, la réponse brute de l'agent,
 * et les métriques essentielles (durée, tokens si disponibles).
 *
 * ## Trois issues possibles
 *
 * - **passed** : toutes les assertions ont passé.
 * - **failed** : une ou plusieurs assertions ont échoué (mais l'agent a bien
 *   répondu). Les détails sont dans `$assertionResults`.
 * - **error** : l'agent a levé une exception avant ou pendant l'appel (agent
 *   introuvable, quota dépassé, LLM timeout…). `$errorMessage` porte le détail.
 */
final class AgentTestResult
{
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ERROR = 'error';

    /**
     * @param array<int, array{name: string, passed: bool, reason: string|null}> $assertionResults
     */
    public function __construct(
        public readonly SynapseAgentTestCase $testCase,
        public readonly string $status,
        public readonly ?string $answer,
        public readonly array $assertionResults,
        public readonly float $durationSeconds,
        public readonly ?int $tokensUsed = null,
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isPassed(): bool
    {
        return self::STATUS_PASSED === $this->status;
    }

    public function isFailed(): bool
    {
        return self::STATUS_FAILED === $this->status;
    }

    public function isError(): bool
    {
        return self::STATUS_ERROR === $this->status;
    }

    /**
     * Nombre d'assertions qui ont échoué. Utile pour le reporting.
     */
    public function failedAssertionsCount(): int
    {
        $count = 0;
        foreach ($this->assertionResults as $r) {
            if (false === $r['passed']) {
                ++$count;
            }
        }

        return $count;
    }
}
