<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Validates Composer configuration for dev vs production setup.
 * Detects path repositories (dev) vs Packagist (production).
 */
class ComposerPathValidator
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function validate(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $composerFile = $projectDir . '/composer.json';

        if (!$this->filesystem->exists($composerFile)) {
            return true;
        }

        $content = (string) file_get_contents($composerFile);
        $composer = json_decode($content, true);
        if (!is_array($composer)) {
            return true;
        }

        if (!isset($composer['repositories'])) {
            $io->writeln('  <info>[OK]</info> Composer (Packagist, production mode)');
            return true;
        }

        $hasPathRepo = false;
        $pathRepos = [];

        foreach ($composer['repositories'] as $repo) {
            if (($repo['type'] ?? null) === 'path') {
                $hasPathRepo = true;
                $pathRepos[] = $repo['url'] ?? 'unknown';
            }
        }

        if ($hasPathRepo) {
            $io->writeln(sprintf('  <comment>[DEV]</comment> Composer (path repositories: %s)', implode(', ', $pathRepos)));
            $io->writeln('         This is development mode. For production, use Packagist instead.');
            return true;
        }

        $io->writeln('  <info>[OK]</info> Composer (Packagist, production mode)');
        return true;
    }
}
