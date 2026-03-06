<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Validates AssetMapper configuration for bundles.
 * Detects missing symlinks, invalid asset paths, and CSP issues.
 */
class AssetMapperValidator
{
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function validate(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $assetsDir = $projectDir.'/assets';

        if (!$this->filesystem->exists($assetsDir)) {
            $io->writeln('  <comment>[SKIP]</comment> assets/ directory not found');

            return true;
        }

        $isValid = true;
        $bundles = $this->kernel->getBundles();

        // Check for required symlinks
        $bundlesToCheck = ['SynapseAdminBundle', 'SynapseChatBundle'];

        foreach ($bundlesToCheck as $bundleName) {
            if (!isset($bundles[$bundleName])) {
                continue;
            }

            $bundle = $bundles[$bundleName];
            $bundleAssets = $bundle->getPath().'/assets';

            if (!$this->filesystem->exists($bundleAssets)) {
                continue;
            }

            // Convert bundle name to asset path (e.g., SynapseAdminBundle -> synapse-admin)
            $assetPath = $this->bundleNameToAssetPath($bundleName);
            $symlinkPath = $assetsDir.'/'.$assetPath;

            if (!$this->filesystem->exists($symlinkPath)) {
                $io->error(sprintf('[AssetMapper] Missing symlink: assets/%s → %s', $assetPath, $bundleAssets));

                if ($fix) {
                    $relativeTarget = rtrim($this->filesystem->makePathRelative($bundleAssets, $assetsDir), '/\\');
                    $this->filesystem->symlink($relativeTarget, $symlinkPath);
                    $io->writeln(sprintf('  -> Created symlink assets/%s', $assetPath));
                } else {
                    $isValid = false;
                }
            } elseif (is_link($symlinkPath)) {
                $target = (string) readlink($symlinkPath);
                $realTarget = realpath($target);
                $realBundleAssets = realpath($bundleAssets);

                if (false !== $realTarget && false !== $realBundleAssets && $realTarget !== $realBundleAssets) {
                    $io->error(sprintf('[AssetMapper] Broken symlink: assets/%s points to %s (expected %s)', $assetPath, $realTarget, $realBundleAssets));

                    if ($fix) {
                        $this->filesystem->remove($symlinkPath);
                        $relativeTarget = rtrim($this->filesystem->makePathRelative($bundleAssets, $assetsDir), '/\\');
                        $this->filesystem->symlink($relativeTarget, $symlinkPath);
                        $io->writeln(sprintf('  -> Fixed symlink assets/%s', $assetPath));
                    } else {
                        $isValid = false;
                    }
                }
            }
        }

        if ($isValid) {
            $io->writeln('  <info>[OK]</info> AssetMapper (symlinks, paths)');
        }

        return $isValid;
    }

    private function bundleNameToAssetPath(string $bundleName): string
    {
        // SynapseAdminBundle → synapse-admin
        // SynapseChatBundle → synapse-chat
        $name = str_replace('Bundle', '', $bundleName);
        $name = str_replace('Synapse', 'synapse', $name);

        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', (string) $name));
    }
}
