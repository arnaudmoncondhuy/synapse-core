<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Command;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande de purge RGPD des conversations
 *
 * Supprime définitivement (hard delete) les conversations plus anciennes
 * que X jours, conformément au droit à l'oubli (RGPD).
 */
#[AsCommand(
    name: 'synapse:purge',
    description: 'Purge RGPD : supprime les conversations plus anciennes que X jours'
)]
class PurgeConversationsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private int $defaultRetentionDays = 30
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_REQUIRED,
                'Durée de rétention en jours',
                $this->defaultRetentionDays
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulation : affiche les conversations à supprimer sans les supprimer'
            )
            ->setHelp(
                <<<HELP
            Cette commande supprime définitivement (hard delete) les conversations
            plus anciennes que la durée de rétention spécifiée.

            Exemples :
              # Purger les conversations de plus de 30 jours (défaut)
              php bin/console synapse:purge

              # Purger les conversations de plus de 90 jours
              php bin/console synapse:purge --days=90

              # Simulation (affiche sans supprimer)
              php bin/console synapse:purge --dry-run

            ⚠️  ATTENTION : Cette opération est IRRÉVERSIBLE !
            Les conversations et leurs messages sont supprimés définitivement.
            HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $days = (int) $input->getOption('days');
        $dryRun = (bool) $input->getOption('dry-run');

        // Validation
        if ($days <= 0) {
            $io->error('La durée de rétention doit être supérieure à 0 jours.');
            return Command::FAILURE;
        }

        // Header
        $io->title('🗑️  Purge RGPD des conversations Synapse');
        $io->writeln(sprintf(
            'Durée de rétention : <fg=yellow>%d jours</>',
            $days
        ));

        if ($dryRun) {
            $io->warning('MODE SIMULATION : Aucune suppression ne sera effectuée');
        } else {
            $io->caution('⚠️  MODE RÉEL : Les conversations seront supprimées définitivement !');
        }

        // Récupérer les conversations à supprimer
        $io->section('🔍 Recherche des conversations à purger...');
        /** @var SynapseConversationRepository<SynapseConversation> $conversationRepo */
        $conversationRepo = $this->em->getRepository(SynapseConversation::class);
        $conversations = $conversationRepo->findOlderThan($days);

        if (empty($conversations)) {
            $io->success('✅ Aucune conversation à purger.');
            return Command::SUCCESS;
        }

        $count = count($conversations);
        $io->writeln(sprintf(
            '<fg=red>%d conversation(s)</> trouvée(s) à supprimer.',
            $count
        ));

        // Afficher un récapitulatif
        $this->displaySummary($io, $conversations);

        // Confirmation en mode réel
        if (!$dryRun) {
            if (!$io->confirm('⚠️  Confirmer la suppression définitive ?', false)) {
                $io->warning('Opération annulée.');
                return Command::SUCCESS;
            }
        }

        // Suppression
        if (!$dryRun) {
            $io->section('🗑️  Suppression en cours...');
            $io->progressStart($count);
            $deleted = 0;

            foreach ($conversations as $conversation) {
                try {
                    $conversationRepo->hardDelete([$conversation]);
                    $deleted++;
                } catch (\Exception $e) {
                    $io->error(sprintf(
                        'Erreur lors de la suppression de la conversation %s : %s',
                        $conversation->getId(),
                        $e->getMessage()
                    ));
                }
                $io->progressAdvance();
            }

            $io->progressFinish();

            $io->success(sprintf(
                '✅ %d/%d conversation(s) supprimée(s) avec succès.',
                $deleted,
                $count
            ));
        } else {
            $io->note('Mode simulation : aucune suppression effectuée.');
        }

        return Command::SUCCESS;
    }

    /**
     * Affiche un récapitulatif des conversations à supprimer
     *
     * @param SynapseConversation[] $conversations
     */
    private function displaySummary(SymfonyStyle $io, array $conversations): void
    {
        // Regrouper par propriétaire
        $byOwner = [];
        foreach ($conversations as $conversation) {
            $ownerEntity = $conversation->getOwner();
            $owner = $ownerEntity !== null ? $ownerEntity->getIdentifier() : 'unknown';
            if (!isset($byOwner[$owner])) {
                $byOwner[$owner] = 0;
            }
            $byOwner[$owner]++;
        }

        // Afficher sous forme de table
        $table = new Table($io);
        $table->setHeaders(['Propriétaire', 'Nombre de conversations']);

        foreach ($byOwner as $owner => $count) {
            $table->addRow([$owner, $count]);
        }

        $table->render();

        // Statistiques supplémentaires
        $oldest = null;
        $newest = null;

        foreach ($conversations as $conversation) {
            $updatedAt = $conversation->getUpdatedAt();
            if ($oldest === null || $updatedAt < $oldest) {
                $oldest = $updatedAt;
            }
            if ($newest === null || $updatedAt > $newest) {
                $newest = $updatedAt;
            }
        }

        if ($oldest !== null && $newest !== null) {
            $io->writeln('');
            $io->writeln(sprintf(
                '📅 Plage de dates : de <fg=cyan>%s</> à <fg=cyan>%s</>',
                $oldest->format('Y-m-d H:i:s'),
                $newest->format('Y-m-d H:i:s')
            ));
        }
    }
}
