<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Governance;

use ArnaudMoncondhuy\SynapseCore\Agent\AgentResolver;
use ArnaudMoncondhuy\SynapseCore\Agent\Input;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgent;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseAgentTestCase;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentTestCaseRepository;

/**
 * Exécute la batterie de tests reproductibles d'un agent — Garde-fou #4.
 *
 * Appelé par la commande `synapse:agent:test-suite` et (à terme) par l'outil
 * MCP `run_agent_test_suite`. Encapsule :
 *   1. La résolution de l'agent via {@see AgentResolver} (un test case peut
 *      donc cibler aussi bien un agent BDD qu'un agent code).
 *   2. L'exécution synchrone de chaque cas.
 *   3. L'évaluation des assertions déclaratives stockées sur le test case.
 *   4. La collecte d'un rapport agrégé ({@see AgentTestResult}).
 *
 * Aucun branchement BDD côté persistance des résultats pour cette première
 * itération : le rapport est retourné en RAM. Une évolution ultérieure
 * (`SynapseAgentTestRun`) persistera les runs de suite pour corréler avant /
 * après modification du prompt.
 */
class AgentTestRunner
{
    public function __construct(
        private readonly AgentResolver $agentResolver,
        private readonly SynapseAgentTestCaseRepository $testCaseRepository,
    ) {
    }

    /**
     * Exécute tous les cas actifs de l'agent donné et retourne le rapport.
     *
     * @return array<int, AgentTestResult>
     */
    public function runSuite(SynapseAgent $agent): array
    {
        $cases = $this->testCaseRepository->findActiveForAgent($agent);
        $results = [];
        foreach ($cases as $case) {
            $results[] = $this->runCase($case);
        }

        return $results;
    }

    /**
     * Exécute un cas de test unique.
     */
    public function runCase(SynapseAgentTestCase $case): AgentTestResult
    {
        $agent = $case->getAgent();
        if (null === $agent) {
            return new AgentTestResult(
                testCase: $case,
                status: AgentTestResult::STATUS_ERROR,
                answer: null,
                assertionResults: [],
                durationSeconds: 0.0,
                errorMessage: sprintf('Test case "%s" is orphaned — its agent was deleted.', $case->getName()),
            );
        }

        $start = microtime(true);
        try {
            $context = $this->agentResolver->createRootContext(
                origin: 'test_suite',
            );
            $resolved = $this->agentResolver->resolve($agent->getKey(), $context);

            $structured = $case->getStructuredInput();
            $input = [] !== $structured
                ? Input::ofStructured($structured)
                : Input::ofMessage($case->getMessage());

            $output = $resolved->call($input, ['context' => $context]);
        } catch (\Throwable $e) {
            return new AgentTestResult(
                testCase: $case,
                status: AgentTestResult::STATUS_ERROR,
                answer: null,
                assertionResults: [],
                durationSeconds: microtime(true) - $start,
                errorMessage: $e->getMessage(),
            );
        }

        $duration = microtime(true) - $start;
        $answer = $output->getAnswer();

        $assertionResults = $this->evaluateAssertions($case->getAssertions(), (string) $answer);
        $allPassed = true;
        foreach ($assertionResults as $r) {
            if (false === $r['passed']) {
                $allPassed = false;
                break;
            }
        }

        $usage = $output->getUsage();
        $tokens = null;
        if (isset($usage['total_tokens']) && is_numeric($usage['total_tokens'])) {
            $tokens = (int) $usage['total_tokens'];
        }

        return new AgentTestResult(
            testCase: $case,
            status: $allPassed ? AgentTestResult::STATUS_PASSED : AgentTestResult::STATUS_FAILED,
            answer: $answer,
            assertionResults: $assertionResults,
            durationSeconds: $duration,
            tokensUsed: $tokens,
        );
    }

    /**
     * Évalue chaque assertion déclarative stockée sur le test case contre la
     * réponse de l'agent. Implémentation volontairement simple : pas d'appel
     * LLM ici — c'est le Garde-fou #2 (LLM-as-Judge) qui ajoutera un scoring
     * sémantique à une étape ultérieure.
     *
     * @param array<string, mixed> $assertions
     *
     * @return array<int, array{name: string, passed: bool, reason: string|null}>
     */
    private function evaluateAssertions(array $assertions, string $answer): array
    {
        $results = [];

        $contains = $assertions['contains'] ?? null;
        if (is_array($contains)) {
            foreach ($contains as $needle) {
                if (!is_string($needle) || '' === $needle) {
                    continue;
                }
                $passed = false !== mb_stripos($answer, $needle);
                $results[] = [
                    'name' => sprintf('contains: "%s"', $needle),
                    'passed' => $passed,
                    'reason' => $passed ? null : sprintf('Substring "%s" not found in answer.', $needle),
                ];
            }
        }

        $notContains = $assertions['not_contains'] ?? null;
        if (is_array($notContains)) {
            foreach ($notContains as $needle) {
                if (!is_string($needle) || '' === $needle) {
                    continue;
                }
                $passed = false === mb_stripos($answer, $needle);
                $results[] = [
                    'name' => sprintf('not_contains: "%s"', $needle),
                    'passed' => $passed,
                    'reason' => $passed ? null : sprintf('Forbidden substring "%s" found in answer.', $needle),
                ];
            }
        }

        $minLength = $assertions['min_length'] ?? null;
        if (is_numeric($minLength)) {
            $min = (int) $minLength;
            $actual = mb_strlen($answer);
            $passed = $actual >= $min;
            $results[] = [
                'name' => sprintf('min_length: %d', $min),
                'passed' => $passed,
                'reason' => $passed ? null : sprintf('Answer length %d is below minimum %d.', $actual, $min),
            ];
        }

        $maxLength = $assertions['max_length'] ?? null;
        if (is_numeric($maxLength)) {
            $max = (int) $maxLength;
            $actual = mb_strlen($answer);
            $passed = $actual <= $max;
            $results[] = [
                'name' => sprintf('max_length: %d', $max),
                'passed' => $passed,
                'reason' => $passed ? null : sprintf('Answer length %d exceeds maximum %d.', $actual, $max),
            ];
        }

        return $results;
    }
}
