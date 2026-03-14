<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Command;

use ArnaudMoncondhuy\SynapseCore\Doctor\AssetMapperValidator;
use ArnaudMoncondhuy\SynapseCore\Doctor\ComposerPathValidator;
use ArnaudMoncondhuy\SynapseCore\Doctor\DoctrineMappingValidator;
use ArnaudMoncondhuy\SynapseCore\Doctor\RepositoryValidator;
use ArnaudMoncondhuy\SynapseCore\Doctor\TypedPropertyValidator;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation as BaseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage as BaseMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'synapse:doctor',
    description: 'Diagnose and repair SynapseBundle integration. Use --init for first-time setup.',
)]
class SynapseDoctorCommand extends Command
{
    private Filesystem $filesystem;
    private bool $hasAdmin;
    private bool $hasChat;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ParameterBagInterface $parameterBag,
        ?Filesystem $filesystem = null,
    ) {
        parent::__construct();
        $this->filesystem = $filesystem ?? new Filesystem();

        $bundles = $this->kernel->getBundles();
        $this->hasAdmin = isset($bundles['SynapseAdminBundle']);
        $this->hasChat = isset($bundles['SynapseChatBundle']);
    }

    protected function configure(): void
    {
        $this
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Auto-repair detected issues')
            ->addOption('init', null, InputOption::VALUE_NONE, 'First-time setup: create all missing files and configuration');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Synapse Doctor');

        $projectDir = $this->kernel->getProjectDir();
        $fix = $input->getOption('fix') || $input->getOption('init');
        $init = $input->getOption('init');

        $checks = [];

        $checks['PHP version (8.2+)'] = $this->checkPhpVersion($io);
        $checks['Intl extension'] = $this->checkIntl($io);
        $checks['Sodium extension'] = $this->checkSodium($io);
        $checks['Bundle registration'] = $this->checkBundleRegistration($projectDir, $fix, $io);
        $checks['Core config (synapse.yaml)'] = $this->checkCoreConfig($projectDir, $fix, $io);
        $checks['Routes'] = $this->checkRoutes($projectDir, $fix, $io);
        $checks['Entities'] = $this->checkEntities($projectDir, $fix, $io);
        $checks['Repository classes'] = $this->checkRepositories($projectDir, $fix, $io);
        $checks['Doctrine mappings'] = $this->checkDoctrineMappings($projectDir, $fix, $io);
        $checks['Security'] = $this->checkSecurity($projectDir, $fix, $io);

        if ($this->hasChat) {
            $checks['Importmap (Stimulus)'] = $this->checkImportmap($projectDir, $fix, $io);
            $checks['Stimulus Bootstrap'] = $this->checkStimulusBootstrap($projectDir, $fix, $io);
        }

        $checks['AssetMapper'] = $this->checkAssetMapper($projectDir, $fix, $io);
        $checks['Typed properties'] = $this->checkTypedProperties($projectDir, $fix, $io);
        $checks['Composer configuration'] = $this->checkComposerConfig($projectDir, $fix, $io);

        $checks['Database connection'] = $this->checkDatabase($io);
        $checks['Database tables'] = $this->checkDatabaseTables($io);

        if ($this->hasChat) {
            $checks['Conversation owner (User entity)'] = $this->checkConversationOwner($io);
        }

        if ($init) {
            $this->runInit($projectDir, $io);
        }

        $this->printSummary($checks, $io);

        $errors = count(array_filter($checks, fn ($v) => false === $v));

        if (0 === $errors) {
            $io->success('All checks passed! Synapse is ready.');

            return Command::SUCCESS;
        }

        if (!$fix) {
            $io->note(sprintf('%d issue(s) detected. Run with --fix to auto-repair, or --init for first-time setup.', $errors));
        }

        return Command::FAILURE;
    }

    // ── Checks ────────────────────────────────────────────────────────────────

    private function checkPhpVersion(SymfonyStyle $io): bool
    {
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $io->error(sprintf('[PHP] Version %s detected. PHP 8.2+ required.', PHP_VERSION));

            return false;
        }
        $io->writeln(sprintf('  <info>[OK]</info> PHP %s', PHP_VERSION));

        return true;
    }

    private function checkIntl(SymfonyStyle $io): bool
    {
        if (!extension_loaded('intl')) {
            $io->error('[PHP] Extension "intl" not found. It is required for translations and localization.');

            return false;
        }
        $io->writeln('  <info>[OK]</info> Intl extension');

        return true;
    }

    private function checkSodium(SymfonyStyle $io): bool
    {
        if (!extension_loaded('sodium')) {
            $io->writeln('  <comment>[WARN]</comment> Sodium extension not found (optional — needed for message encryption)');
        } else {
            $io->writeln('  <info>[OK]</info> Sodium extension');
        }

        return true;
    }

    private function checkBundleRegistration(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $bundlesFile = $projectDir.'/config/bundles.php';
        if (!$this->filesystem->exists($bundlesFile)) {
            $io->error('[Bundles] config/bundles.php not found.');

            return false;
        }

        $content = (string) file_get_contents($bundlesFile);
        $isValid = true;

        $expected = ['ArnaudMoncondhuy\\SynapseCore\\SynapseCoreBundle' => 'SynapseCoreBundle'];
        if ($this->hasAdmin) {
            $expected['ArnaudMoncondhuy\\SynapseAdmin\\SynapseAdminBundle'] = 'SynapseAdminBundle';
        }
        if ($this->hasChat) {
            $expected['ArnaudMoncondhuy\\SynapseChat\\SynapseChatBundle'] = 'SynapseChatBundle';
        }

        foreach ($expected as $fqn => $name) {
            if (!str_contains($content, $fqn)) {
                $io->error(sprintf('[Bundles] %s not registered in bundles.php', $name));
                $isValid = false;
            }
        }

        // Detect stale meta-bundle
        if (str_contains($content, 'ArnaudMoncondhuy\\SynapseBundle\\SynapseBundle')) {
            $io->warning('[Bundles] Old meta-package SynapseBundle still registered.');
            if ($fix) {
                $updated = (string) preg_replace(
                    '/\s*ArnaudMoncondhuy\\\\SynapseBundle\\\\SynapseBundle::class\s*=>\s*\[.*?\],\n?/s',
                    '',
                    $content
                );
                $this->filesystem->dumpFile($bundlesFile, $updated);
                $io->writeln('  -> Old bundle removed from bundles.php');
            } else {
                $isValid = false;
            }
        }

        if ($isValid) {
            $io->writeln('  <info>[OK]</info> Bundles registered');
        }

        return $isValid;
    }

    private function checkCoreConfig(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $configPath = $projectDir.'/config/packages/synapse.yaml';
        if (!$this->filesystem->exists($configPath)) {
            $io->error('[Config] config/packages/synapse.yaml missing.');
            if ($fix) {
                $this->filesystem->dumpFile($configPath, $this->getDefaultCoreConfig());
                $io->writeln('  -> synapse.yaml created.');
            }

            return false;
        }
        $io->writeln('  <info>[OK]</info> config/packages/synapse.yaml');

        return true;
    }

    private function checkRoutes(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $mainRoutesFile = $projectDir.'/config/routes.yaml';
        $hasRoutes = false;

        if ($this->filesystem->exists($mainRoutesFile)) {
            $content = (string) file_get_contents($mainRoutesFile);
            $hasRoutes = str_contains($content, 'type: synapse')
                || str_contains($content, 'SynapseAdminBundle')
                || str_contains($content, 'SynapseChatBundle');

            // Also scan sub-files referenced in routes.yaml
            if (!$hasRoutes) {
                preg_match_all('/resource:\s+[\'"]?(\S+)[\'"]?/', $content, $matches);
                foreach ($matches[1] as $resource) {
                    if (str_starts_with($resource, '@') || str_starts_with($resource, 'http')) {
                        continue;
                    }
                    $subFile = $projectDir.'/config/'.ltrim($resource, './');
                    if (!$this->filesystem->exists($subFile)) {
                        $subFile = $projectDir.'/'.ltrim($resource, './');
                    }
                    if ($this->filesystem->exists($subFile)) {
                        $sub = (string) file_get_contents($subFile);
                        if (
                            str_contains($sub, 'SynapseAdminBundle')
                            || str_contains($sub, 'SynapseChatBundle')
                            || str_contains($sub, 'type: synapse')
                        ) {
                            $hasRoutes = true;
                            break;
                        }
                    }
                }
            }
        }

        if (!$hasRoutes) {
            $io->error('[Routes] No Synapse routes configured.');
            $io->writeln('         Add to config/routes.yaml:');
            $io->writeln('           _synapse:');
            $io->writeln('               resource: .');
            $io->writeln('               type: synapse');
            if ($fix) {
                $this->addSynapseRouteEntry($projectDir, $io);
            }

            return false;
        }

        $io->writeln('  <info>[OK]</info> Routes');

        return true;
    }

    private function checkEntities(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $convClass = $this->parameterBag->get('synapse.persistence.conversation_class');
        $msgClass = $this->parameterBag->get('synapse.persistence.message_class');

        if (!$convClass && !$msgClass) {
            $io->writeln('  <comment>[INFO]</comment> No custom entity classes configured');

            return true;
        }

        $isValid = true;

        if ($convClass) {
            $convClassStr = is_string($convClass) ? $convClass : '';
            if (!class_exists($convClassStr)) {
                $io->error(sprintf('[Entities] %s not found.', $convClassStr));
                if ($fix && 'App\\Entity\\SynapseConversation' === $convClassStr) {
                    $this->createConversationEntity($projectDir, $io);
                } else {
                    $isValid = false;
                }
            } elseif (!is_subclass_of($convClassStr, BaseConversation::class)) {
                $io->error(sprintf('[Entities] %s must extend %s', $convClassStr, BaseConversation::class));
                $isValid = false;
            } elseif (!property_exists($convClassStr, 'messages')) {
                $io->error(sprintf('[Entities] %s must declare $messages with #[ORM\\OneToMany]', $convClassStr));
                $isValid = false;
            } else {
                $io->writeln(sprintf('  <info>[OK]</info> Conversation: %s', $convClassStr));
            }
        }

        if ($msgClass) {
            $msgClassStr = is_string($msgClass) ? $msgClass : '';
            if (!class_exists($msgClassStr)) {
                $io->error(sprintf('[Entities] %s not found.', $msgClassStr));
                if ($fix && 'App\\Entity\\SynapseMessage' === $msgClassStr) {
                    $this->createMessageEntity($projectDir, $io);
                } else {
                    $isValid = false;
                }
            } elseif (!is_subclass_of($msgClassStr, BaseMessage::class)) {
                $io->error(sprintf('[Entities] %s must extend %s', $msgClassStr, BaseMessage::class));
                $isValid = false;
            } elseif (!property_exists($msgClassStr, 'conversation')) {
                $io->error(sprintf('[Entities] %s must declare $conversation with #[ORM\\ManyToOne]', $msgClassStr));
                $isValid = false;
            } else {
                $io->writeln(sprintf('  <info>[OK]</info> Message: %s', $msgClassStr));
            }
        }

        return $isValid;
    }

    private function checkSecurity(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $securityFile = $projectDir.'/config/packages/security.yaml';
        if (!$this->filesystem->exists($securityFile)) {
            $io->error('[Security] config/packages/security.yaml not found.');
            if ($fix) {
                $this->generateSecurityConfig($projectDir, $io);

                return true;
            }

            return false;
        }

        $content = (string) file_get_contents($securityFile);
        if (!str_contains($content, 'firewalls:')) {
            $io->error('[Security] No firewall configured in security.yaml.');

            return false;
        }

        $adminRole = ($this->parameterBag->has('synapse.security.admin_role')
            ? $this->parameterBag->get('synapse.security.admin_role')
            : null) ?: 'ROLE_ADMIN';
        $chatRole = ($this->parameterBag->has('synapse.security.chat_role')
            ? $this->parameterBag->get('synapse.security.chat_role')
            : null) ?: 'ROLE_USER';

        $adminPrefix = $this->parameterBag->has('synapse.admin_prefix') ? $this->parameterBag->get('synapse.admin_prefix') : '/synapse/admin';
        $chatPrefix = $this->parameterBag->has('synapse.chat_ui_prefix') ? $this->parameterBag->get('synapse.chat_ui_prefix') : '/synapse/chat';

        $adminPrefixStr = is_string($adminPrefix) ? $adminPrefix : '/synapse/admin';
        $adminRoleStr = is_string($adminRole) ? $adminRole : 'ROLE_ADMIN';
        $chatPrefixStr = is_string($chatPrefix) ? $chatPrefix : '/synapse/chat';
        $chatRoleStr = is_string($chatRole) ? $chatRole : 'ROLE_USER';

        $hasAdminControl = str_contains($content, $adminPrefixStr);
        $hasChatControl = str_contains($content, $chatPrefixStr);

        if ($this->hasAdmin && !$hasAdminControl) {
            $io->writeln(sprintf('  <comment>[WARN]</comment> No access_control for %s in security.yaml', $adminPrefixStr));
            $io->writeln(sprintf('         Add: - { path: ^%s, roles: %s }', $adminPrefixStr, $adminRoleStr));
            $io->writeln(sprintf('              - { path: ^%s, roles: %s }', $chatPrefixStr, $chatRoleStr));
        } else {
            $io->writeln(sprintf('  <info>[OK]</info> Security (admin: %s, chat: %s)', $adminRoleStr, $chatRoleStr));
        }

        return true;
    }

    private function checkImportmap(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $importmapFile = $projectDir.'/importmap.php';
        if (!$this->filesystem->exists($importmapFile)) {
            $io->writeln('  <comment>[SKIP]</comment> importmap.php not found');

            return true;
        }

        $content = (string) file_get_contents($importmapFile);
        $hasError = false;
        if (!str_contains($content, 'synapse-chat/controllers/synapse_chat_controller.js')) {
            $io->writeln('  <comment>[INFO]</comment> Legacy synapse_chat_controller missing from importmap.php (Optional if using V2)');
        }

        if (!str_contains($content, 'synapse-chat/controllers/synapse_sidebar_controller.js')) {
            $io->writeln('  <comment>[INFO]</comment> Legacy synapse_sidebar_controller missing from importmap.php (Optional if using V2)');
        }

        // --- NEW V2 ASSETS ---

        if (!str_contains($content, 'synapse-chat/controllers/synapse_chat_controller.js')) {
            $io->error('[Importmap] Consolidated controller (synapse_chat_controller.js) missing from importmap.php.');
            $io->writeln("         Add: 'synapse-chat/controllers/synapse_chat_controller.js' => ['path' => 'synapse-chat/controllers/synapse_chat_controller.js']");
            if ($fix) {
                $entry = "\n    'synapse-chat/controllers/synapse_chat_controller.js' => [\n"
                    ."        'path' => 'synapse-chat/controllers/synapse_chat_controller.js',\n"
                    ."    ],\n";
                $content = (string) preg_replace('/(];\s*)$/', $entry.'$1', $content);
                $this->filesystem->dumpFile($importmapFile, $content);
                $io->writeln('  -> importmap.php updated with synapse_chat_controller.');
                $hasError = true;
            } else {
                $hasError = true;
            }
        }

        if (!str_contains($content, 'synapse-chat/styles/synapse_chat.css')) {
            $io->error('[Importmap] Consolidated CSS (synapse_chat.css) missing from importmap.php.');
            $io->writeln("         Add: 'synapse-chat/styles/synapse_chat.css' => ['path' => 'synapse-chat/styles/synapse_chat.css']");
            if ($fix) {
                $entry = "\n    'synapse-chat/styles/synapse_chat.css' => [\n"
                    ."        'path' => 'synapse-chat/styles/synapse_chat.css',\n"
                    ."    ],\n";
                $content = (string) preg_replace('/(];\s*)$/', $entry.'$1', $content);
                $this->filesystem->dumpFile($importmapFile, $content);
                $io->writeln('  -> importmap.php updated with synapse_chat.css.');
                $hasError = true;
            } else {
                $hasError = true;
            }
        }

        if ($hasError) {
            return false;
        }

        $io->writeln('  <info>[OK]</info> Importmap (Stimulus controllers)');

        return true;
    }

    private function checkStimulusBootstrap(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $importmapFile = $projectDir.'/importmap.php';
        $bootstrapFile = $projectDir.'/assets/stimulus_bootstrap.js'; // Default

        if ($this->filesystem->exists($importmapFile)) {
            $map = include $importmapFile;
            if (isset($map['app']['path'])) {
                $bootstrapFile = $projectDir.'/assets/'.ltrim($map['app']['path'], './');
            }
        }

        if (!$this->filesystem->exists($bootstrapFile)) {
            // Fallback to bootstrap.js if stimulus_bootstrap.js not found and not in importmap
            if (!str_contains($bootstrapFile, 'stimulus_bootstrap.js')) {
                $io->writeln(sprintf('  <comment>[SKIP]</comment> Stimulus entrypoint %s not found', $bootstrapFile));

                return true;
            }
            $bootstrapFile = $projectDir.'/assets/bootstrap.js';
        }

        if (!$this->filesystem->exists($bootstrapFile)) {
            $io->writeln('  <comment>[SKIP]</comment> stimulus_bootstrap.js not found');

            return true;
        }

        $content = (string) file_get_contents($bootstrapFile);
        $hasError = false;

        if (!str_contains($content, 'synapse-chat/controllers/synapse_chat_controller.js')) {
            $io->error('[Stimulus] V2 controller (synapse_chat_controller.js) not registered in stimulus_bootstrap.js.');
            $io->writeln("         Add: import SynapseChatController from '@arnaudmoncondhuy/synapse-chat/synapse_chat_controller';");
            $io->writeln("              app.register('synapse-chat', SynapseChatController);");

            if ($fix) {
                // Try to insert before startStimulusApp or at the end
                if (str_contains($content, 'import { startStimulusApp }')) {
                    $content = str_replace(
                        'import { startStimulusApp }',
                        "import SynapseChatController from '@arnaudmoncondhuy/synapse-chat/synapse_chat_controller';\nimport { startStimulusApp }",
                        $content
                    );
                } else {
                    $content = "import SynapseChatController from '@arnaudmoncondhuy/synapse-chat/synapse_chat_controller';\n".$content;
                }

                if (str_contains($content, 'const app = startStimulusApp();')) {
                    $content = str_replace(
                        'const app = startStimulusApp();',
                        "const app = startStimulusApp();\napp.register('synapse-chat', SynapseChatController);",
                        $content
                    );
                } else {
                    $content .= "\napp.register('synapse-chat', SynapseChatController);\n";
                }

                $this->filesystem->dumpFile($bootstrapFile, $content);
                $io->writeln('  -> stimulus_bootstrap.js updated.');
                $hasError = true;
            } else {
                $hasError = true;
            }
        }

        if ($hasError) {
            return false;
        }

        $io->writeln('  <info>[OK]</info> Stimulus Bootstrap');

        return true;
    }

    private function checkDatabase(SymfonyStyle $io): bool
    {
        try {
            $connection = $this->kernel->getContainer()->get('doctrine.dbal.default_connection');
            if (!$connection instanceof \Doctrine\DBAL\Connection) {
                throw new \RuntimeException('Database connection not found');
            }
            $connection->executeQuery('SELECT 1');
            $io->writeln('  <info>[OK]</info> Database connection');

            return true;
        } catch (\Exception $e) {
            $io->error('[Database] Cannot connect: '.$e->getMessage());

            return false;
        }
    }

    private function checkDatabaseTables(SymfonyStyle $io): bool
    {
        $expected = ['synapse_conversation', 'synapse_message', 'synapse_model_preset', 'synapse_provider', 'synapse_model', 'synapse_config'];
        try {
            $connection = $this->kernel->getContainer()->get('doctrine.dbal.default_connection');
            if (!$connection instanceof \Doctrine\DBAL\Connection) {
                throw new \RuntimeException('Database connection not found');
            }
            $existing = $connection->createSchemaManager()->listTableNames();
            $missing = array_diff($expected, $existing);

            if (!empty($missing)) {
                $io->error(sprintf('[Database] Missing tables: %s', implode(', ', $missing)));
                $io->writeln('         Run: bin/console doctrine:migrations:migrate');

                return false;
            }

            // pgvector (optional)
            try {
                $hasPgvector = (bool) $connection->executeQuery("SELECT 1 FROM pg_extension WHERE extname = 'vector'")->fetchOne();
                $suffix = $hasPgvector ? ' + pgvector' : ' (pgvector not installed — needed for vector memory)';
                $io->writeln(sprintf('  <info>[OK]</info> Database tables%s', $suffix));
            } catch (\Exception) {
                $io->writeln('  <info>[OK]</info> Database tables');
            }

            return true;
        } catch (\Exception $e) {
            $io->writeln('  <comment>[WARN]</comment> Could not verify tables: '.$e->getMessage());

            return false;
        }
    }

    private function checkConversationOwner(SymfonyStyle $io): bool
    {
        try {
            $connection = $this->kernel->getContainer()->get('doctrine.dbal.default_connection');
            if (!$connection instanceof \Doctrine\DBAL\Connection) {
                throw new \RuntimeException('Database connection not found');
            }
            // Try to find the User/Owner table (check common table names)
            $userTables = ['users', 'app_user', 'user'];
            $existing = $connection->createSchemaManager()->listTableNames();
            $userTableFound = null;

            foreach ($userTables as $table) {
                if (in_array($table, $existing, true)) {
                    $userTableFound = $table;
                    break;
                }
            }

            if (!$userTableFound) {
                $io->error('[Chat] No user table found (checked: '.implode(', ', $userTables).')');
                $io->writeln('         Create an entity implementing ConversationOwnerInterface with a "users" table.');

                return false;
            }

            // Check if there are any users
            $userCountFetch = $connection->executeQuery("SELECT COUNT(*) FROM $userTableFound")->fetchOne();
            $userCount = is_numeric($userCountFetch) ? (int) $userCountFetch : 0;

            if (0 === $userCount) {
                $io->writeln(sprintf('  <comment>[WARN]</comment> No users found in %s table', $userTableFound));
                $io->writeln('         Run: bin/console doctrine:fixtures:load --append');

                return false;
            }

            $io->writeln(sprintf('  <info>[OK]</info> Chat owner (%s users)', $userCount));

            return true;
        } catch (\Exception $e) {
            $io->writeln('  <comment>[WARN]</comment> Could not verify chat owner: '.$e->getMessage());

            return false;
        }
    }

    private function checkRepositories(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $validator = new RepositoryValidator($this->filesystem);

        return $validator->validate($projectDir, $fix, $io);
    }

    private function checkDoctrineMappings(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $validator = new DoctrineMappingValidator($this->filesystem);

        return $validator->validate($projectDir, $fix, $io);
    }

    private function checkComposerConfig(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $validator = new ComposerPathValidator($this->filesystem);

        return $validator->validate($projectDir, $fix, $io);
    }

    private function checkTypedProperties(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $validator = new TypedPropertyValidator($this->filesystem);

        return $validator->validate($projectDir, $fix, $io);
    }

    private function checkAssetMapper(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $validator = new AssetMapperValidator($this->filesystem, $this->kernel);

        return $validator->validate($projectDir, $fix, $io);
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    private function runInit(string $projectDir, SymfonyStyle $io): void
    {
        $io->section('Init — creating missing files');

        // synapse.yaml
        $configPath = $projectDir.'/config/packages/synapse.yaml';
        if (!$this->filesystem->exists($configPath)) {
            $this->filesystem->dumpFile($configPath, $this->getDefaultCoreConfig());
            $io->writeln('  -> Created config/packages/synapse.yaml');
        }

        // Routes
        $this->addSynapseRouteEntry($projectDir, $io);

        // Entities — use configured class names if set, otherwise fall back to defaults.
        // synapse.yaml may have just been created this run (container not reloaded yet),
        // so the parameter may be null/empty even if has() returns true.
        $convClass = ($this->parameterBag->has('synapse.persistence.conversation_class')
            ? $this->parameterBag->get('synapse.persistence.conversation_class')
            : null) ?: 'App\\Entity\\SynapseConversation';
        $msgClass = ($this->parameterBag->has('synapse.persistence.message_class')
            ? $this->parameterBag->get('synapse.persistence.message_class')
            : null) ?: 'App\\Entity\\SynapseMessage';
        $convClassStr = is_string($convClass) ? $convClass : '';
        $msgClassStr = is_string($msgClass) ? $msgClass : '';

        if ('' !== $convClassStr && !class_exists($convClassStr) && str_starts_with($convClassStr, 'App\\')) {
            $this->createConversationEntity($projectDir, $io);
        }
        if ('' !== $msgClassStr && !class_exists($msgClassStr) && str_starts_with($msgClassStr, 'App\\')) {
            $this->createMessageEntity($projectDir, $io);
        }

        // Security
        $securityFile = $projectDir.'/config/packages/security.yaml';
        if (!$this->filesystem->exists($securityFile)) {
            $this->generateSecurityConfig($projectDir, $io);
        }

        $io->writeln('');
        $io->writeln('  <info>Next steps:</info>');
        $io->writeln('  1. bin/console doctrine:migrations:diff');
        $io->writeln('  2. bin/console doctrine:migrations:migrate');
        $io->writeln('  3. Visit /admin to configure your LLM provider');
    }

    // ── Fixes ─────────────────────────────────────────────────────────────────

    private function addSynapseRouteEntry(string $projectDir, SymfonyStyle $io): void
    {
        $routesFile = $projectDir.'/config/routes.yaml';
        if (!$this->filesystem->exists($routesFile)) {
            return;
        }

        $content = (string) file_get_contents($routesFile);
        if (
            str_contains($content, 'type: synapse')
            || str_contains($content, 'SynapseAdminBundle')
            || str_contains($content, 'SynapseChatBundle')
        ) {
            return;
        }

        $entry = "\n# Synapse — loads Admin (/admin) and Chat routes automatically\n"
            ."_synapse:\n"
            ."    resource: .\n"
            ."    type: synapse\n";

        $this->filesystem->dumpFile($routesFile, $content.$entry);
        $io->writeln('  -> Added Synapse route loader to config/routes.yaml');
    }

    private function generateSecurityConfig(string $projectDir, SymfonyStyle $io): void
    {
        $adminRole = ($this->parameterBag->has('synapse.security.admin_role')
            ? $this->parameterBag->get('synapse.security.admin_role')
            : null) ?: 'ROLE_ADMIN';
        $chatRole = ($this->parameterBag->has('synapse.security.chat_role')
            ? $this->parameterBag->get('synapse.security.chat_role')
            : null) ?: 'ROLE_USER';

        $adminPrefix = $this->parameterBag->has('synapse.admin_prefix') ? $this->parameterBag->get('synapse.admin_prefix') : '/synapse/admin';
        $chatPrefix = $this->parameterBag->has('synapse.chat_ui_prefix') ? $this->parameterBag->get('synapse.chat_ui_prefix') : '/synapse/chat';

        $adminPrefixStr = is_string($adminPrefix) ? $adminPrefix : '/synapse/admin';
        $adminRoleStr = is_string($adminRole) ? $adminRole : 'ROLE_ADMIN';
        $chatPrefixStr = is_string($chatPrefix) ? $chatPrefix : '/synapse/chat';
        $chatRoleStr = is_string($chatRole) ? $chatRole : 'ROLE_USER';

        $template = <<<YAML
security:
    password_hashers:
        Symfony\\Component\\Security\\Core\\User\\InMemoryUser: 'auto'

    providers:
        users_in_memory:
            memory:
                users:
                    # Dev credentials: admin / admin — replace for production
                    admin: { password: '\$2y\$13\$bBql5D2aPJh.ecpkJRCNOejSNtrcOa69XWsUaRE1bE5kDl8kAVfFq', roles: ['ROLE_ADMIN'] }

    firewalls:
        dev:
            pattern: ^/(_(profiler|wdt)|css|images|js)/
            security: false
        main:
            lazy: true
            provider: users_in_memory
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
            logout:
                path: app_logout

    access_control:
        - { path: ^{$adminPrefixStr}, roles: {$adminRoleStr} }
        - { path: ^{$chatPrefixStr}, roles: {$chatRoleStr} }
YAML;

        $this->filesystem->dumpFile(
            $projectDir.'/config/packages/security.yaml',
            $template
        );

        $controllerPath = $projectDir.'/src/Controller/SecurityController.php';
        if (!$this->filesystem->exists($controllerPath)) {
            $this->filesystem->dumpFile($controllerPath, <<<'PHP'
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('@Synapse/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): void {}
}
PHP);
        }

        $io->writeln('  -> Created security.yaml (dev: admin / admin)');
        $io->writeln('  -> Created src/Controller/SecurityController.php');
    }

    private function createConversationEntity(string $projectDir, SymfonyStyle $io): void
    {
        $path = $projectDir.'/src/Entity/SynapseConversation.php';
        if ($this->filesystem->exists($path)) {
            return;
        }
        $this->filesystem->dumpFile($path, <<<'PHP'
<?php

namespace App\Entity;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation as BaseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SynapseConversationRepository::class)]
#[ORM\Table(name: 'synapse_conversation')]
class SynapseConversation extends BaseConversation
{
    #[ORM\OneToMany(targetEntity: SynapseMessage::class, mappedBy: 'conversation', cascade: ['persist', 'remove'])]
    protected Collection $messages;

    public function __construct()
    {
        parent::__construct();
        $this->messages = new ArrayCollection();
    }

    public function getOwner(): ?ConversationOwnerInterface
    {
        // Optional: link to your User entity here
        return null;
    }

    public function setOwner(ConversationOwnerInterface $owner): static
    {
        return $this;
    }
}
PHP);
        $io->writeln('  -> Created src/Entity/SynapseConversation.php');
    }

    private function createMessageEntity(string $projectDir, SymfonyStyle $io): void
    {
        $path = $projectDir.'/src/Entity/SynapseMessage.php';
        if ($this->filesystem->exists($path)) {
            return;
        }
        $this->filesystem->dumpFile($path, <<<'PHP'
<?php

namespace App\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation as BaseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage as BaseMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SynapseMessageRepository::class)]
#[ORM\Table(name: 'synapse_message')]
class SynapseMessage extends BaseMessage
{
    #[ORM\ManyToOne(targetEntity: SynapseConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected SynapseConversation $conversation;

    public function getConversation(): BaseConversation
    {
        return $this->conversation;
    }

    public function setConversation(BaseConversation $conversation): static
    {
        if (!$conversation instanceof SynapseConversation) {
            throw new \InvalidArgumentException('conversation must be App\Entity\SynapseConversation');
        }
        $this->conversation = $conversation;
        return $this;
    }
}
PHP);
        $io->writeln('  -> Created src/Entity/SynapseMessage.php');
    }

    // ── Summary ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, bool> $checks
     */
    private function printSummary(array $checks, SymfonyStyle $io): void
    {
        $io->section('Summary');
        $io->writeln(sprintf(
            'Bundles: Core [OK]%s%s',
            $this->hasAdmin ? ' · Admin [OK]' : '',
            $this->hasChat ? ' · Chat [OK]' : ''
        ));
        $io->writeln('');

        foreach ($checks as $label => $status) {
            $tag = $status ? '<info>[OK]</info> ' : '<error>[FAIL]</error>';
            $io->writeln(sprintf('  %s %s', $tag, $label));
        }
    }

    private function getDefaultCoreConfig(): string
    {
        $path = __DIR__.'/../../../config/synapse.yaml';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
