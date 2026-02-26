<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Command;

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
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[AsCommand(
    name: 'synapse:doctor',
    description: 'Diagnostique et répare l\'intégration de SynapseBundle dans votre projet.',
)]
class SynapseDoctorCommand extends Command
{
    private Filesystem $filesystem;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly ParameterBagInterface $parameterBag,
        private readonly PermissionCheckerInterface $permissionChecker,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        ?Filesystem $filesystem = null
    ) {
        parent::__construct();
        $this->filesystem = $filesystem ?? new Filesystem();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Tente de réparer automatiquement les problèmes détectés');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('🩺 Synapse Doctor - Diagnostic de l\'intégration');

        $projectDir = $this->kernel->getProjectDir();
        $fix = $input->getOption('fix');

        $results = [];
        $errors = 0;

        // 0. Vérification de l'environnement
        $results['env'] = $this->checkEnvironment($io);

        // 1. Vérification de la configuration
        $results['config'] = $this->checkConfig($projectDir, $fix, $io);

        // 2. Vérification des entités
        $results['entities'] = $this->checkEntities($projectDir, $fix, $io);

        // 3. Vérification de la sécurité
        $results['security'] = $this->checkSecurity($io);

        // 4. Vérification des assets
        $results['assets'] = $this->checkAssets($projectDir, $fix, $io);

        // 5. Vérification des routes
        $results['routes'] = $this->checkRoutes($projectDir, $fix, $io);

        $io->section('Résumé du diagnostic');
        foreach ($results as $category => $status) {
            if ($status === false) {
                $errors++;
            }
        }

        if ($errors === 0) {
            $io->success('Tout semble correct ! Votre intégration de SynapseBundle est opérationnelle.');
            return Command::SUCCESS;
        }

        if (!$fix) {
            $io->warning(sprintf('Il y a %d problème(s) détecté(s). Relancez avec --fix pour tenter une réparation automatique.', $errors));
        }

        return Command::FAILURE;
    }

    private function checkConfig(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $configPath = $projectDir . '/config/packages/synapse.yaml';
        if (!$this->filesystem->exists($configPath)) {
            $io->error('Fichier config/packages/synapse.yaml manquant.');
            if ($fix) {
                $io->note('Création du fichier de configuration par défaut...');
                $defaultConfig = <<<YAML
synapse:
    persistence:
        enabled: true
        conversation_class: 'App\Entity\SynapseConversation'
        message_class: 'App\Entity\SynapseMessage'
    admin:
        enabled: true
YAML;
                $this->filesystem->dumpFile($configPath, $defaultConfig);
                $io->success('Fichier créé.');
                return true;
            }
            return false;
        }
        $io->writeln('✅ Configuration : config/packages/synapse.yaml détecté.');
        return true;
    }

    private function checkEntities(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $convClass = $this->parameterBag->get('synapse.persistence.conversation_class') ?? 'App\Entity\SynapseConversation';
        $msgClass = $this->parameterBag->get('synapse.persistence.message_class') ?? 'App\Entity\SynapseMessage';

        $isValid = true;

        // Vérification Conversation
        if (!class_exists($convClass)) {
            $io->error(sprintf('Entité %s manquante.', $convClass));
            if ($fix && $convClass === 'App\Entity\SynapseConversation') {
                $this->createConversationEntity($projectDir, $io);
            } else {
                $isValid = false;
            }
        } else {
            $io->writeln(sprintf('✅ Entité Conversation : %s détectée.', $convClass));
            // Vérification de l'héritage
            if (!is_subclass_of($convClass, BaseConversation::class)) {
                $io->error(sprintf('L\'entité %s doit étendre %s', $convClass, BaseConversation::class));
                $isValid = false;
            }
            // Vérification du mapping inverse (messages)
            if (!property_exists($convClass, 'messages')) {
                $io->error(sprintf('L\'entité %s doit redéfinir la propriété $messages avec l\'attribut #[ORM\OneToMany]', $convClass));
                $isValid = false;
            }
        }

        // Vérification Message
        if (!class_exists($msgClass)) {
            $io->error(sprintf('Entité %s manquante.', $msgClass));
            if ($fix && $msgClass === 'App\Entity\SynapseMessage') {
                $this->createMessageEntity($projectDir, $io);
            } else {
                $isValid = false;
            }
        } else {
            $io->writeln(sprintf('✅ Entité Message : %s détectée.', $msgClass));
            if (!is_subclass_of($msgClass, BaseMessage::class)) {
                $io->error(sprintf('L\'entité %s doit étendre %s', $msgClass, BaseMessage::class));
                $isValid = false;
            }
            // Vérification de la relation vers conversation
            if (!property_exists($msgClass, 'conversation')) {
                $io->error(sprintf('L\'entité %s doit redéfinir la propriété $conversation avec l\'attribut #[ORM\ManyToOne]', $msgClass));
                $isValid = false;
            }
        }

        return $isValid;
    }

    private function checkAssets(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $isValid = true;

        // 1. importmap.php
        $importmapPath = $projectDir . '/importmap.php';
        if ($this->filesystem->exists($importmapPath)) {
            $content = file_get_contents($importmapPath);
            if (strpos($content, 'synapse/controllers/synapse_chat_controller.js') === false) {
                $io->error('Les assets Synapse manquent dans importmap.php');
                if ($fix) {
                    $io->note('Mise à jour de importmap.php...');
                    // Utilisation des chemins logiques du mapper (le namespace 'synapse' est déjà enregistré par l'Extension)
                    $newEntry = "    'synapse/controllers/synapse_chat_controller.js' => [\n        'path' => 'synapse/controllers/synapse_chat_controller.js',\n    ],\n    'synapse/controllers/synapse_sidebar_controller.js' => [\n        'path' => 'synapse/controllers/synapse_sidebar_controller.js',\n    ],\n";
                    $content = preg_replace('/(\];\s*)$/', $newEntry . "$1", $content);
                    $this->filesystem->dumpFile($importmapPath, $content);
                    $io->success('importmap.php mis à jour.');
                } else {
                    $isValid = false;
                }
            }
        }

        // 2. app.css (Injection automatique de l'import)
        $cssPath = $projectDir . '/assets/styles/app.css';
        if ($this->filesystem->exists($cssPath)) {
            $content = file_get_contents($cssPath);
            if (strpos($content, 'synapse/styles/synapse.css') === false) {
                $io->error('Les imports CSS Synapse manquent dans app.css');
                if ($fix) {
                    $io->note('Injection des @import dans app.css...');
                    $imports = "\n/* --- Synapse Bundle --- */\n@import \"synapse/styles/synapse.css\";\n@import \"synapse/styles/sidebar.css\";\n";
                    $content = $imports . $content;
                    $this->filesystem->dumpFile($cssPath, $content);
                    $io->success('app.css mis à jour.');
                } else {
                    $isValid = false;
                }
            }
        }

        // 2. controllers.json
        $controllersPath = $projectDir . '/assets/controllers.json';
        if ($this->filesystem->exists($controllersPath)) {
            $content = file_get_contents($controllersPath);
            if (strpos($content, 'arnaudmoncondhuy/synapse-bundle') === false) {
                $io->error('Le bundle n\'est pas enregistré dans assets/controllers.json (Stimulus)');
                if ($fix) {
                    $io->note('Mise à jour de controllers.json...');
                    $json = json_decode($content, true);
                    $json['controllers']['arnaudmoncondhuy/synapse-bundle'] = [
                        'synapse-chat' => [
                            'enabled' => true,
                            'fetch' => 'eager',
                        ],
                        'synapse-sidebar' => [
                            'enabled' => true,
                            'fetch' => 'eager',
                        ]
                    ];
                    $this->filesystem->dumpFile($controllersPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                    $io->success('controllers.json mis à jour.');
                    return true;
                }
                $isValid = false;
            }
        }

        if ($isValid) {
            $io->writeln('✅ Assets : Configuration AssetMapper/Stimulus semble correcte.');
        }

        return $isValid;
    }

    private function checkRoutes(string $projectDir, bool $fix, SymfonyStyle $io): bool
    {
        $routePath = $projectDir . '/config/routes/synapse.yaml';
        if (!$this->filesystem->exists($routePath)) {
            $io->error('Fichier config/routes/synapse.yaml manquant.');
            if ($fix) {
                $io->note('Création du fichier de routes...');
                $routes = <<<YAML
synapse_bundle:
    resource: '@SynapseBundle/config/routes.yaml'
    prefix: /
YAML;
                $this->filesystem->dumpFile($routePath, $routes);
                $io->success('Routes importées.');
                return true;
            }
            return false;
        }
        $io->writeln('✅ Routes : config/routes/synapse.yaml détecté.');
        return true;
    }

    private function createConversationEntity(string $projectDir, SymfonyStyle $io): void
    {
        $path = $projectDir . '/src/Entity/SynapseConversation.php';
        $content = <<<'PHP'
<?php

namespace App\Entity;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation as BaseConversation;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
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
        // Optionnel : liez ici à votre entité User
        return null; 
    }

    public function setOwner(ConversationOwnerInterface $owner): self
    {
        return $this;
    }
}
PHP;
        $this->filesystem->dumpFile($path, $content);
        $io->success('Entité App\Entity\SynapseConversation créée.');
    }

    private function createMessageEntity(string $projectDir, SymfonyStyle $io): void
    {
        $path = $projectDir . '/src/Entity/SynapseMessage.php';
        $content = <<<'PHP'
<?php

namespace App\Entity;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage as BaseMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation as BaseBaseConversation;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'synapse_message')]
class SynapseMessage extends BaseMessage
{
    #[ORM\ManyToOne(targetEntity: SynapseConversation::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(name: 'conversation_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SynapseConversation $conversation;

    public function getConversation(): BaseBaseConversation
    {
        return $this->conversation;
    }

    public function setConversation(BaseBaseConversation $conversation): self
    {
        if (!$conversation instanceof SynapseConversation) {
            throw new \InvalidArgumentException('Must be instance of App\Entity\SynapseConversation');
        }
        $this->conversation = $conversation;
        return $this;
    }
}
PHP;
        $this->filesystem->dumpFile($path, $content);
        $io->success('Entité App\Entity\SynapseMessage créée.');
    }

    private function checkEnvironment(SymfonyStyle $io): bool
    {
        $isValid = true;

        // PHP Version
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $io->error(sprintf('Version PHP insuffisante : %s. PHP 8.2+ est requis.', PHP_VERSION));
            $isValid = false;
        } else {
            $io->writeln(sprintf('✅ PHP : version %s détectée.', PHP_VERSION));
        }

        // Sodium extension
        if (!extension_loaded('sodium')) {
            $io->warning('Extension "sodium" non détectée. Le chiffrement AES-256 ne sera pas disponible.');
        } else {
            $io->writeln('✅ Environnement : Extension sodium détectée.');
        }

        return $isValid;
    }

    private function checkSecurity(SymfonyStyle $io): bool
    {
        $isValid = true;

        // Permission Checker
        $checkerClass = get_class($this->permissionChecker);
        if (str_contains($checkerClass, 'DefaultPermissionChecker')) {
            $io->note('Sécurité : PermissionChecker par défaut utilisé.');

            // Check if Symfony Security is active
            if (!$this->parameterBag->has('security.token_storage')) {
                $io->warning('Sécurité : Symfony Security n\'est pas configuré. L\'accès admin est BLOQUÉ par défaut (Secure by Default).');
            }
        } else {
            $io->writeln(sprintf('✅ Sécurité : PermissionChecker personnalisé détecté (%s).', $checkerClass));
        }

        // CSRF
        if ($this->csrfTokenManager === null) {
            $io->warning('Sécurité : Protection CSRF non disponible (composant security-csrf manquant).');
        } else {
            $io->writeln('✅ Sécurité : Protection CSRF active.');
        }

        // Encryption Key
        $encryptionEnabled = $this->parameterBag->get('synapse.encryption.enabled') ?? false;
        if ($encryptionEnabled) {
            $key = $this->parameterBag->get('synapse.encryption.key');
            if (empty($key) || $key === 'CHANGE_ME' || $key === '%env(SYNAPSE_ENCRYPTION_KEY)%') {
                $io->error('Sécurité : Le chiffrement est activé mais la clé semble non configurée.');
                $isValid = false;
            }
        }

        return $isValid;
    }
}
