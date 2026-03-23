<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Rag\RagSourceRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'synapse:rag:reindex',
    description: 'Réindexe une source RAG depuis son provider enregistré',
)]
class RagReindexCommand extends Command
{
    public function __construct(
        private readonly RagManager $ragManager,
        private readonly RagSourceRegistry $registry,
        private readonly SynapseRagSourceRepository $sourceRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::OPTIONAL, 'Slug de la source à réindexer')
            ->addOption('clear-only', null, InputOption::VALUE_NONE, 'Vider la source sans réindexer')
            ->addOption('list', null, InputOption::VALUE_NONE, 'Lister les providers enregistrés et les sources en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Mode liste
        if ($input->getOption('list')) {
            return $this->listSources($io);
        }

        $slugArg = $input->getArgument('slug');
        $slug = is_string($slugArg) ? $slugArg : '';

        if ('' === $slug) {
            $io->error('Le slug de la source est requis. Utilisez --list pour voir les sources disponibles.');

            return Command::FAILURE;
        }

        // Mode clear-only
        if ($input->getOption('clear-only')) {
            $io->title(sprintf('Nettoyage de la source "%s"', $slug));

            try {
                $this->ragManager->clear($slug);
                $io->success(sprintf('Source "%s" vidée avec succès.', $slug));

                return Command::SUCCESS;
            } catch (\Throwable $e) {
                $io->error(sprintf('Erreur : %s', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        // Mode réindexation
        $io->title(sprintf('Réindexation de la source "%s"', $slug));

        if (!$this->registry->has($slug)) {
            $io->error(sprintf('Aucun RagSourceProvider enregistré pour le slug "%s". Utilisez --list pour voir les providers disponibles.', $slug));

            return Command::FAILURE;
        }

        try {
            $startTime = microtime(true);
            $count = $this->ragManager->reindex($slug);
            $duration = microtime(true) - $startTime;

            $io->success(sprintf(
                'Réindexation terminée : %d chunks créés en %.2f s.',
                $count,
                $duration,
            ));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Erreur lors de la réindexation : %s', $e->getMessage()));

            return Command::FAILURE;
        }
    }

    private function listSources(SymfonyStyle $io): int
    {
        $io->title('Sources RAG');

        // Providers enregistrés (services taggués)
        $providers = $this->registry->getAll();
        if (!empty($providers)) {
            $io->section('Providers enregistrés (services)');
            $rows = [];
            foreach ($providers as $provider) {
                $rows[] = [$provider->getSlug(), $provider->getName(), $provider->getDescription()];
            }
            $io->table(['Slug', 'Nom', 'Description'], $rows);
        } else {
            $io->note('Aucun RagSourceProvider enregistré.');
        }

        // Sources en base
        $sources = $this->sourceRepository->findAllOrdered();
        if (!empty($sources)) {
            $io->section('Sources en base de données');
            $rows = [];
            foreach ($sources as $source) {
                $hasProvider = $this->registry->has($source->getSlug()) ? 'oui' : 'non';
                $rows[] = [
                    $source->getSlug(),
                    $source->getName(),
                    $source->isActive() ? 'actif' : 'inactif',
                    $source->getDocumentCount(),
                    $source->getLastIndexedAt()?->format('d/m/Y H:i') ?? '-',
                    $hasProvider,
                ];
            }
            $io->table(['Slug', 'Nom', 'Statut', 'Documents', 'Dernier index', 'Provider ?'], $rows);
        } else {
            $io->note('Aucune source en base de données.');
        }

        return Command::SUCCESS;
    }
}
