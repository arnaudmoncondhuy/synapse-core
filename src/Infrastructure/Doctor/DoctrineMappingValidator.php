<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates Doctrine entity mapping configuration completeness.
 */
class DoctrineMappingValidator
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function validate(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $doctrineFile = $projectDir . '/config/packages/doctrine.yaml';

        if (!$this->filesystem->exists($doctrineFile)) {
            $io->writeln('  <comment>[SKIP]</comment> Doctrine config not found');
            return true;
        }

        $content = file_get_contents($doctrineFile);
        $config = Yaml::parse($content);

        if (!isset($config['doctrine']['orm']['mappings'])) {
            $io->error('[Doctrine] No ORM mappings configured.');
            if ($fix) {
                $this->addDefaultMappings($doctrineFile, $config, $io);
            }
            return false;
        }

        $mappings = $config['doctrine']['orm']['mappings'];
        $entityDir = $projectDir . '/src/Entity';
        $isValid = true;

        // Check if src/Entity is mapped
        if ($this->filesystem->exists($entityDir)) {
            $entityNamespaceFound = false;

            foreach ($mappings as $namespace => $mapping) {
                if (($mapping['dir'] ?? null) === '%kernel.project_dir%/src/Entity'
                    || ($mapping['path'] ?? null) === '%kernel.project_dir%/src/Entity'
                ) {
                    $entityNamespaceFound = true;
                    break;
                }
            }

            if (!$entityNamespaceFound) {
                $io->error('[Doctrine] src/Entity directory not mapped in doctrine.yaml');
                if ($fix) {
                    $this->addEntityMapping($doctrineFile, $config, $io);
                } else {
                    $isValid = false;
                }
            }
        }

        // Check Synapse entity mappings
        $synapseNamespaces = [
            'ArnaudMoncondhuy\\SynapseCore' => '%kernel.project_dir%/vendor/arnaudmoncondhuy/synapse-bundle/packages/core/src/Storage/Entity',
            'ArnaudMoncondhuy\\SynapseAdmin' => '%kernel.project_dir%/vendor/arnaudmoncondhuy/synapse-bundle/packages/admin/src/Storage/Entity',
            'ArnaudMoncondhuy\\SynapseChat' => '%kernel.project_dir%/vendor/arnaudmoncondhuy/synapse-bundle/packages/chat/src/Storage/Entity',
        ];

        foreach ($synapseNamespaces as $ns => $expectedPath) {
            try {
                $testClass = $ns . '\\SynapseConversation';
                if (class_exists($testClass)) {
                    // Bundle is installed, check if mapped
                    $found = false;
                    foreach ($mappings as $mapping) {
                        if (($mapping['dir'] ?? null) === $expectedPath || ($mapping['path'] ?? null) === $expectedPath) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $io->writeln(sprintf('  <comment>[WARN]</comment> %s bundle installed but not explicitly mapped', $ns));
                    }
                }
            } catch (\Throwable) {
                // Bundle not installed, skip
            }
        }

        return $isValid;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function addDefaultMappings(string $doctrineFile, array $config, SymfonyStyle $io): void
    {
        $config['doctrine']['orm']['mappings'] = $config['doctrine']['orm']['mappings'] ?? [];
        $config['doctrine']['orm']['mappings']['App'] = [
            'dir' => '%kernel.project_dir%/src/Entity',
            'prefix' => 'App\\Entity',
            'type' => 'attribute',
        ];

        $yaml = Yaml::dump($config, 10, 2);
        file_put_contents($doctrineFile, $yaml);
        $io->writeln('  -> Added default App entity mapping to doctrine.yaml');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function addEntityMapping(string $doctrineFile, array $config, SymfonyStyle $io): void
    {
        if (!isset($config['doctrine']['orm']['mappings'])) {
            $config['doctrine']['orm']['mappings'] = [];
        }

        // Only add if not already present
        foreach ($config['doctrine']['orm']['mappings'] as $mapping) {
            if (($mapping['dir'] ?? null) === '%kernel.project_dir%/src/Entity') {
                return;
            }
        }

        $config['doctrine']['orm']['mappings']['App'] = [
            'dir' => '%kernel.project_dir%/src/Entity',
            'prefix' => 'App\\Entity',
            'type' => 'attribute',
        ];

        $yaml = Yaml::dump($config, 10, 2);
        file_put_contents($doctrineFile, $yaml);
        $io->writeln('  -> Added App entity mapping to doctrine.yaml');
    }
}
