<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Engine\ChatService;
use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'synapse:rag:test',
    description: 'Teste la qualité du RAG : retrieval sémantique et (optionnel) réponse LLM complète',
)]
class TestRagCommand extends Command
{
    public function __construct(
        private readonly RagManager $ragManager,
        private readonly SynapseAgentRepository $agentRepository,
        private readonly ChatService $chatService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('query', InputArgument::REQUIRED, 'Question à tester')
            ->addOption('agent', 'a', InputOption::VALUE_REQUIRED, 'Clé de l\'agent (charge ses sources RAG et sa config)')
            ->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Source(s) directe(s) à interroger (slug)')
            ->addOption('min-score', null, InputOption::VALUE_REQUIRED, 'Score de similarité minimum (0.0–1.0)', null)
            ->addOption('max-results', null, InputOption::VALUE_REQUIRED, 'Nombre maximum de chunks retournés', null)
            ->addOption('full', 'f', InputOption::VALUE_NONE, 'Effectuer un vrai appel LLM avec le contexte RAG');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $queryArg = $input->getArgument('query');
        $query = is_string($queryArg) ? $queryArg : '';

        $agentKey = $input->getOption('agent');
        $agentKey = is_string($agentKey) ? $agentKey : null;

        /** @var string[] $directSources */
        $directSources = (array) ($input->getOption('source') ?? []);

        $minScoreOpt = $input->getOption('min-score');
        $maxResultsOpt = $input->getOption('max-results');
        $full = (bool) $input->getOption('full');

        // --- Résolution des paramètres ---
        $sourceSlugs = $directSources;
        $minScore = null !== $minScoreOpt ? (float) $minScoreOpt : 0.4;
        $maxResults = null !== $maxResultsOpt ? (int) $maxResultsOpt : 5;

        if (null !== $agentKey) {
            $agent = $this->agentRepository->findByKey($agentKey);
            if (!$agent) {
                $io->error(sprintf('Agent introuvable : "%s"', $agentKey));

                return Command::FAILURE;
            }

            // Fusionner sources agent + sources directes
            $agentSources = $agent->getAllowedRagSources();
            $sourceSlugs = array_unique(array_merge($agentSources, $sourceSlugs));

            // Utiliser la config de l'agent si non surchargée par les options
            if (null === $minScoreOpt) {
                $minScore = $agent->getRagMinScore();
            }
            if (null === $maxResultsOpt) {
                $maxResults = $agent->getRagMaxResults();
            }

            $io->title(sprintf('RAG Test — Agent : %s', $agent->getName()));
        } else {
            $io->title('RAG Test — Sources directes');
        }

        if (empty($sourceSlugs)) {
            $io->error('Aucune source RAG à interroger. Utilisez --agent ou --source.');

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Query' => $query],
            ['Sources' => implode(', ', $sourceSlugs)],
            ['Min score' => $minScore],
            ['Max résultats' => $maxResults],
        );

        // --- Retrieval ---
        $io->section('Recherche sémantique');

        $startTime = microtime(true);

        try {
            $results = $this->ragManager->search($query, $sourceSlugs, $maxResults, $minScore);
        } catch (\Throwable $e) {
            $io->error(sprintf('Erreur lors du retrieval : %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $duration = (int) round((microtime(true) - $startTime) * 1000);

        if (empty($results)) {
            $io->warning(sprintf('Aucun chunk trouvé au-dessus du score minimum %.2f (en %dms)', $minScore, $duration));
        } else {
            $io->success(sprintf('%d chunk(s) trouvé(s) en %dms', count($results), $duration));

            $rows = [];
            foreach ($results as $i => $result) {
                $excerpt = str_replace(["\n", "\r"], ' ', $result['content'] ?? '');
                $excerpt = mb_strlen($excerpt) > 100 ? mb_substr($excerpt, 0, 100).'…' : $excerpt;
                $rows[] = [
                    (string) ($i + 1),
                    $result['sourceSlug'] ?? '—',
                    sprintf('%.3f', $result['score'] ?? 0),
                    $excerpt,
                ];
            }

            $io->table(['#', 'Source', 'Score', 'Extrait (100 car.)'], $rows);

            // Affichage détaillé avec -v
            if ($output->isVerbose()) {
                foreach ($results as $i => $result) {
                    $io->section(sprintf('Chunk #%d — %s (score %.3f)', $i + 1, $result['sourceSlug'] ?? '?', $result['score'] ?? 0));
                    $io->text($result['content'] ?? '');
                    if (!empty($result['metadata'])) {
                        $io->note('Métadonnées : '.json_encode($result['metadata'], JSON_UNESCAPED_UNICODE));
                    }
                }
            }
        }

        // --- Appel LLM complet (optionnel) ---
        if ($full) {
            $io->section('Réponse LLM complète (--full)');

            if (null === $agentKey) {
                $io->warning('Pas d\'agent spécifié. L\'appel LLM n\'utilisera pas de configuration personnalisée.');
            }

            $llmOptions = ['stateless' => true, 'debug' => false];
            if (null !== $agentKey) {
                $llmOptions['agent'] = $agentKey;
            }

            $io->text('Appel en cours...');
            $llmStart = microtime(true);

            try {
                $llmResult = $this->chatService->ask($query, $llmOptions);
                $llmDuration = (int) round((microtime(true) - $llmStart) * 1000);

                $io->success(sprintf('Réponse obtenue en %dms', $llmDuration));
                $io->text('');
                $io->writeln($llmResult['answer'] ?? '(réponse vide)');

                if (!empty($llmResult['usage'])) {
                    $usage = $llmResult['usage'];
                    $io->newLine();
                    $io->table(
                        ['Tokens prompt', 'Tokens complétion', 'Modèle'],
                        [[
                            $usage['prompt_tokens'] ?? '?',
                            $usage['completion_tokens'] ?? '?',
                            $llmResult['model'] ?? '?',
                        ]]
                    );
                }
            } catch (\Throwable $e) {
                $io->error(sprintf('Erreur LLM : %s', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }
}
