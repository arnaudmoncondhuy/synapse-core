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

        $content = (string) file_get_contents($doctrineFile);
        $config = Yaml::parse($content);
        if (!is_array($config)) {
            return true;
        }

        $mappings = null;
        if (
            isset($config['doctrine']) && is_array($config['doctrine'])
            && isset($config['doctrine']['orm']) && is_array($config['doctrine']['orm'])
            && isset($config['doctrine']['orm']['mappings']) && is_array($config['doctrine']['orm']['mappings'])
        ) {
            $mappings = $config['doctrine']['orm']['mappings'];
        }

        if ($mappings === null) {
            $io->error('[Doctrine] No ORM mappings configured.');
            if ($fix) {
                $doctrineConfig = (isset($config['doctrine']) && is_array($config['doctrine'])) ? $config['doctrine'] : [];
                $this->addDefaultMappings($doctrineFile, $doctrineConfig, $io);
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
                if (
                    is_array($mapping) && (($mapping['dir'] ?? null) === '%kernel.project_dir%/src/Entity'
                        || ($mapping['path'] ?? null) === '%kernel.project_dir%/src/Entity')
                ) {
                    $entityNamespaceFound = true;
                    break;
                }
            }

            if (!$entityNamespaceFound) {
                $io->error('[Doctrine] src/Entity directory not mapped in doctrine.yaml');
                if ($fix) {
                    $this->addEntityMapping($doctrineFile, is_array($config['doctrine']) ? $config['doctrine'] : [], $io);
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
                        if (is_array($mapping) && (($mapping['dir'] ?? null) === $expectedPath || ($mapping['path'] ?? null) === $expectedPath)) {
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
     * @param array<mixed> $doctrineConfig
     */
    private function addDefaultMappings(string $doctrineFile, array $doctrineConfig, SymfonyStyle $io): void
    {
        if (!isset($doctrineConfig['orm'])) {
            $doctrineConfig['orm'] = [];
        }
        if (!is_array($doctrineConfig['orm'])) {
            $doctrineConfig['orm'] = [];
        }

        $doctrineConfig['orm']['mappings'] = $doctrineConfig['orm']['mappings'] ?? [];
        if (!is_array($doctrineConfig['orm']['mappings'])) {
            $doctrineConfig['orm']['mappings'] = [];
        }

        $doctrineConfig['orm']['mappings']['App'] = [
            'dir' => '%kernel.project_dir%/src/Entity',
            'prefix' => 'App\\Entity',
            'type' => 'attribute',
        ];

        $config = ['doctrine' => $doctrineConfig];
        $yaml = Yaml::dump($config, 10, 2);
        file_put_contents($doctrineFile, $yaml);
        $io->writeln('  -> Added default App entity mapping to doctrine.yaml');
    }

    /**
     * @param array<mixed> $doctrineConfig
     */
    private function addEntityMapping(string $doctrineFile, array $doctrineConfig, SymfonyStyle $io): void
    {
        if (!isset($doctrineConfig['orm']) || !is_array($doctrineConfig['orm'])) {
            $doctrineConfig['orm'] = [];
        }
        if (!isset($doctrineConfig['orm']['mappings']) || !is_array($doctrineConfig['orm']['mappings'])) {
            $doctrineConfig['orm']['mappings'] = [];
        }

        // Only add if not already present
        foreach ($doctrineConfig['orm']['mappings'] as $mapping) {
            if (
                is_array($mapping)
                && isset($mapping['dir']) && $mapping['dir'] === '%kernel.project_dir%/src/Entity'
            ) {
                return;
            }
        }

        $doctrineConfig['orm']['mappings']['App'] = [
            'dir' => '%kernel.project_dir%/src/Entity',
            'prefix' => 'App\\Entity',
            'type' => 'attribute',
        ];

        $config = ['doctrine' => $doctrineConfig];
        $yaml = Yaml::dump($config, 10, 2);
        file_put_contents($doctrineFile, $yaml);
        $io->writeln('  -> Added App entity mapping to doctrine.yaml');
    }
}
