<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Commande CLI : Met à jour le fichier VERSION du bundle avec le numéro de version dépendant de la date.
 *
 * Format : dev 0.YYMMDD (inversé : année-mois-jour)
 * Utilisation : symfony console synapse:version:update
 * Fichier cible : racine du projet/VERSION
 */
#[AsCommand(
    name: 'synapse:version:update',
    description: 'Updates the application version file with the current inverted date format.',
)]
class UpdateVersionCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Format: dev 0.260222 (inverted date YYMMDD)
        $date = (new \DateTime())->format('ymd');
        $version = "dev 0.{$date}";

        $rootPath = dirname(__DIR__, 3);
        $versionFile = $rootPath . '/VERSION';

        if (file_put_contents($versionFile, $version . PHP_EOL) === false) {
            $io->error(sprintf('Could not write to version file at %s', $versionFile));
            return Command::FAILURE;
        }

        $io->success(sprintf('Version updated to: %s', $version));

        return Command::SUCCESS;
    }
}
