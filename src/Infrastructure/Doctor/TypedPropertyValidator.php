<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Doctor;

use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Validates typed property initialization in Doctrine entities.
 * Detects uninitialized Collection properties that may cause runtime errors.
 */
class TypedPropertyValidator
{
    public function __construct(
        private readonly Filesystem $filesystem,
    ) {}

    public function validate(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $entityDir = $projectDir . '/src/Entity';

        if (!$this->filesystem->exists($entityDir)) {
            return true;
        }

        $phpFiles = glob($entityDir . '/*.php');
        $isValid = true;

        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            $baseName = basename($file);

            // Check for OneToMany/ManyToMany relations without initialization
            if (preg_match('/(?:OneToMany|ManyToMany)/', $content)) {
                $issues = $this->detectUninitializedProperties($content, $baseName);

                foreach ($issues as $issue) {
                    $io->writeln(sprintf('  <comment>[WARN]</comment> %s: %s', $baseName, $issue));

                    if ($fix) {
                        $this->addPostLoadCallback($file, $io);
                    } else {
                        $isValid = false;
                    }
                }
            }
        }

        if ($isValid) {
            $io->writeln('  <info>[OK]</info> Typed properties (Collections)');
        }

        return $isValid;
    }

    private function detectUninitializedProperties(string $content, string $baseName): array
    {
        $issues = [];

        // Check if Collection properties lack initialization in constructor
        if (preg_match('/Collection\s+\$(\w+)/', $content, $matches)) {
            $propertyName = $matches[1];

            // Check if constructor initializes it
            if (!preg_match('/\$this->' . $propertyName . '\s*=\s*new\s+ArrayCollection/', $content)) {
                // Check if there's a @PostLoad callback
                if (!preg_match('/#\[ORM\\\\PostLoad\]/', $content)) {
                    $issues[] = sprintf('Collection property $%s not initialized in constructor or @PostLoad callback', $propertyName);
                }
            }
        }

        return $issues;
    }

    private function addPostLoadCallback(string $file, SymfonyStyle $io): void
    {
        $content = file_get_contents($file);

        // Check if @PostLoad already exists
        if (preg_match('/#\[ORM\\\\PostLoad\]/', $content)) {
            return;
        }

        // Find the class closing brace
        $lastBracePos = strrpos($content, '}');
        if ($lastBracePos === false) {
            return;
        }

        $postLoadMethod = <<<'PHP'

    #[ORM\PostLoad]
    public function initializeCollections(): void
    {
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getProperties() as $property) {
            if ($property->getType()?->getName() === 'Doctrine\\Common\\Collections\\Collection') {
                $property->setAccessible(true);
                if (!isset($property->getValue($this))) {
                    $property->setValue($this, new \Doctrine\Common\Collections\ArrayCollection());
                }
            }
        }
    }
PHP;

        $content = substr_replace($content, $postLoadMethod, $lastBracePos, 0);
        file_put_contents($file, $content);
        $io->writeln(sprintf('  -> Added @PostLoad callback to %s', basename($file)));
    }
}
