<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Governance\AgentTestResult;
use ArnaudMoncondhuy\SynapseCore\Governance\AgentTestRunner;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Exécute la batterie de tests reproductibles d'un agent — Garde-fou #4.
 *
 * Usage :
 *   bin/console synapse:agent:test-suite support_client
 *   bin/console synapse:agent:test-suite support_client --fail-threshold=2
 *
 * Code de sortie :
 *   - 0 : tous les cas sont passés.
 *   - 1 : au moins un cas a échoué (ou le seuil `--fail-threshold` est dépassé).
 *   - 2 : erreur d'exécution (agent introuvable, cas orphelin, exception LLM).
 *
 * Ce code de sortie permet l'intégration dans un pipeline CI/CD : un `set -e`
 * bloque la publication d'un prompt modifié si des régressions sont détectées.
 */
#[AsCommand(
    name: 'synapse:agent:test-suite',
    description: 'Exécute la batterie de tests reproductibles d\'un agent (garde-fou #4).',
)]
class AgentTestSuiteCommand extends Command
{
    public function __construct(
        private readonly SynapseAgentRepository $agentRepository,
        private readonly AgentTestRunner $testRunner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('agent', InputArgument::REQUIRED, 'Clé de l\'agent à tester')
            ->addOption('fail-threshold', null, InputOption::VALUE_REQUIRED, 'Nombre maximum d\'échecs tolérés avant de retourner un code d\'erreur', '0')
            ->addOption('verbose-answers', null, InputOption::VALUE_NONE, 'Afficher les réponses brutes de l\'agent pour chaque cas');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keyArg = $input->getArgument('agent');
        $key = is_string($keyArg) ? $keyArg : '';

        $agent = $this->agentRepository->findByKey($key);
        if (null === $agent) {
            $io->error(sprintf('Agent "%s" introuvable.', $key));

            return Command::FAILURE;
        }

        $io->title(sprintf('Tests reproductibles — %s (%s)', $agent->getName(), $agent->getKey()));

        $results = $this->testRunner->runSuite($agent);
        if ([] === $results) {
            $io->warning('Aucun cas de test actif pour cet agent. Créez-en via l\'admin ou un data fixture.');

            return Command::SUCCESS;
        }

        $passed = 0;
        $failed = 0;
        $errored = 0;
        $showAnswers = (bool) $input->getOption('verbose-answers');

        foreach ($results as $result) {
            $this->renderResult($io, $result, $showAnswers);
            if ($result->isPassed()) {
                ++$passed;
            } elseif ($result->isFailed()) {
                ++$failed;
            } else {
                ++$errored;
            }
        }

        $total = count($results);
        $io->section('Résumé');
        $io->table(
            ['Statut', 'Nombre'],
            [
                ['Réussis', (string) $passed],
                ['Échoués', (string) $failed],
                ['Erreurs', (string) $errored],
                ['Total', (string) $total],
            ],
        );

        $thresholdRaw = $input->getOption('fail-threshold');
        $threshold = is_numeric($thresholdRaw) ? (int) $thresholdRaw : 0;
        $nonPassing = $failed + $errored;

        if ($errored > 0) {
            $io->error(sprintf('%d cas en erreur d\'exécution.', $errored));

            return 2;
        }

        if ($nonPassing > $threshold) {
            $io->error(sprintf('Seuil de régression dépassé : %d échec(s) > seuil %d.', $nonPassing, $threshold));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d/%d cas réussis.', $passed, $total));

        return Command::SUCCESS;
    }

    private function renderResult(SymfonyStyle $io, AgentTestResult $result, bool $showAnswers): void
    {
        $case = $result->testCase;
        $label = sprintf('[%s] %s', strtoupper($result->status), $case->getName());

        if ($result->isPassed()) {
            $io->writeln(sprintf('<info>✓ %s</info> (%.2fs)', $label, $result->durationSeconds));
        } elseif ($result->isFailed()) {
            $io->writeln(sprintf('<comment>✗ %s</comment> (%.2fs)', $label, $result->durationSeconds));
            foreach ($result->assertionResults as $r) {
                if (false === $r['passed']) {
                    $io->writeln(sprintf('    - %s — <error>%s</error>', $r['name'], $r['reason'] ?? 'failed'));
                }
            }
        } else {
            $io->writeln(sprintf('<error>✗ %s</error>', $label));
            if (null !== $result->errorMessage) {
                $io->writeln(sprintf('    Erreur : %s', $result->errorMessage));
            }
        }

        if ($showAnswers && null !== $result->answer) {
            $io->writeln('    Réponse :');
            foreach (explode("\n", $result->answer) as $line) {
                $io->writeln('      '.$line);
            }
        }
    }
}
