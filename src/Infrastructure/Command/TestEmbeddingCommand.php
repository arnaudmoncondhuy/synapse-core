<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Command;

use ArnaudMoncondhuy\SynapseCore\Core\Service\EmbeddingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'synapse:debug:embedding',
    description: 'Teste la génération d\'un embedding avec le provider actif',
)]
class TestEmbeddingCommand extends Command
{
    public function __construct(
        private readonly EmbeddingService $embeddingService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::REQUIRED, 'Le texte à vectoriser')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Modèle spécifique (si non fourni: defaut du provider actif)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $text = $input->getArgument('text');
        $model = $input->getOption('model');

        $io->title('Test de génération d\'Embedding Synapse');

        try {
            if ($model) {
                $io->note(sprintf('Modèle demandé: %s', $model));
            } else {
                $io->note('Résolution automatique du modèle par défaut pour le provider actif...');
            }

            $io->text(sprintf('Texte d\'entrée: "%s"', $text));
            $io->text('Génération en cours...');

            $startTime = microtime(true);
            $result = $this->embeddingService->generateEmbeddings($text, $model);
            $duration = microtime(true) - $startTime;

            $io->success(sprintf('Embedding généré avec succès (en %.2f s) !', $duration));

            $embeddings = $result['embeddings'] ?? [];
            if (empty($embeddings) || empty($embeddings[0])) {
                $io->error('Aucun vecteur retourné.');
                return Command::FAILURE;
            }

            $vector = $embeddings[0];
            $dimension = count($vector);
            $io->info(sprintf('Dimension du vecteur: %d', $dimension));

            // Afficher les 5 premières valeurs
            $sample = array_slice($vector, 0, 5);
            $sampleStr = implode(', ', array_map(fn($v) => sprintf('%.4f', $v), $sample));
            $io->text(sprintf('Extrait des 5 premières valeurs: [%s, ...]', $sampleStr));

            if (isset($result['usage'])) {
                $io->info('Usage Token:');
                $io->table(
                    ['Type', 'Tokens'],
                    [
                        ['Prompt Tokens', $result['usage']['prompt_tokens'] ?? 0],
                        ['Total Tokens', $result['usage']['total_tokens'] ?? 0],
                    ]
                );
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf("Erreur lors de la génération: %s\nFichier: %s:%d", $e->getMessage(), $e->getFile(), $e->getLine()));
            return Command::FAILURE;
        }
    }
}
