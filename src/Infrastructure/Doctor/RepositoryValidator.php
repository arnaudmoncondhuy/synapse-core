<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Validates repository class references in entity attributes.
 * Detects orphaned references and suggests proper imports.
 */
class RepositoryValidator
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function validate(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $isValid = true;
        $entityDir = $projectDir . '/src/Entity';

        if (!$this->filesystem->exists($entityDir)) {
            return true;
        }

        $phpFiles = glob($entityDir . '/*.php') ?: [];
        foreach ($phpFiles as $file) {
            $content = (string) file_get_contents($file);

            // Extract namespace and imports
            preg_match('/namespace\s+([^;]+);/', $content, $nsMatches);
            $namespace = $nsMatches[1] ?? 'App';

            $imports = [];
            preg_match_all('/^use\s+([^;]+);/m', $content, $importMatches);
            foreach ($importMatches[1] as $import) {
                $parts = explode(' as ', $import);
                $fqn = trim($parts[0]);
                $alias = trim($parts[1] ?? '') ?: substr($fqn, strrpos($fqn, '\\') + 1);
                $imports[$alias] = $fqn;
            }

            // Check for repositoryClass attribute
            if (preg_match('/repositoryClass:\s*([A-Za-z0-9\\\\]+)/', $content, $matches)) {
                $className = $matches[1];

                // Resolve the class name
                $resolvedClass = $this->resolveClassName($className, $imports, $namespace);

                // Check if the class can be resolved
                if (!class_exists($resolvedClass)) {
                    $io->error(sprintf('[Repositories] %s: %s not found', basename($file), $resolvedClass));

                    // Suggest common fixes
                    $suggestions = $this->suggestRepositoryImports($className);
                    foreach ($suggestions as $suggestion) {
                        $io->writeln(sprintf('         Suggestion: use %s;', $suggestion));
                    }

                    if ($fix) {
                        // Try to add import
                        if (count($suggestions) === 1) {
                            $this->addImport($file, $suggestions[0], $io);
                        }
                    } else {
                        $isValid = false;
                    }
                }
            }
        }

        return $isValid;
    }

    /**
     * @param array<string, string> $imports
     */
    private function resolveClassName(string $className, array $imports, string $namespace): string
    {
        // If already fully qualified
        if (str_contains($className, '\\')) {
            return $className;
        }

        // Check if it's in the imports
        if (isset($imports[$className])) {
            return $imports[$className];
        }

        // Try namespace-relative
        return $namespace . '\\' . $className;
    }

    /**
     * @return string[]
     */
    private function suggestRepositoryImports(string $fqn): array
    {
        $basename = substr($fqn, strrpos($fqn, '\\') + 1);
        $suggestions = [];

        // App repository
        $appRepo = 'App\\Repository\\' . $basename;
        if (class_exists($appRepo)) {
            $suggestions[] = $appRepo;
        }

        // Synapse base repository
        $synapseRepo = 'ArnaudMoncondhuy\\SynapseCore\\Storage\\Repository\\' . $basename;
        if (class_exists($synapseRepo)) {
            $suggestions[] = $synapseRepo;
        }

        return $suggestions;
    }

    private function addImport(string $file, string $className, SymfonyStyle $io): void
    {
        $content = (string) file_get_contents($file);
        $namespace = 'App\\Repository';

        // Find the position to insert the import (after namespace declaration)
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $insertPos = strpos($content, 'namespace');
            $pos = strpos($content, ';', (int) $insertPos);
            if ($pos === false) {
                return;
            }
            $insertPos = $pos + 1;
            $import = "\nuse {$className};";
            $content = substr_replace($content, $import, $insertPos, 0);

            file_put_contents($file, $content);
            $io->writeln(sprintf('  -> Added import to %s', basename($file)));
        }
    }
}
