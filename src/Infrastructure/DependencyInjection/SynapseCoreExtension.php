<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\DependencyInjection;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageRepository;
use ArnaudMoncondhuy\SynapseCore\Core\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\LibsodiumEncryptionService;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Extension principale du conteneur de dépendance pour SynapseBundle.
 *
 * Responsabilités :
 * 1. Charger la configuration et injecter les paramètres.
 * 2. Charger les services définis dans `config/services.yaml`.
 * 3. Configurer l'auto-configuration pour simplifier l'utilisation des interfaces (Tags automatiques).
 * 4. Pré-configurer Twig (Namespace) et AssetMapper (chemins) via `prepend()`.
 */
class SynapseCoreExtension extends Extension implements PrependExtensionInterface
{
    /**
     * Pré-configuration des autres bundles (Twig, AssetMapper).
     *
     * Cette méthode est appelée avant le chargement des configurations de l'application.
     * Elle permet au bundle de s'injecter automatiquement sans configuration manuelle de l'utilisateur.
     */
    public function prepend(ContainerBuilder $container): void
    {
        // Note: Core is 100% headless, no Twig namespace registration needed
        // Twig namespaces are registered by Admin and Chat bundles only

        // 2. Enregistrement des assets pour AssetMapper (Stimulus controllers)
        $frameworkConfig = [
            'asset_mapper' => [
                'paths' => [
                    realpath(dirname(__DIR__, 3) . '/assets') => 'synapse',
                ],
            ],
        ];

        // Only prepend messenger config if the component is installed
        if (interface_exists(\Symfony\Component\Messenger\MessageBusInterface::class)) {
            $frameworkConfig['messenger'] = [
                'transports' => [
                    'synapse_async' => 'doctrine://default?auto_setup=true',
                ],
                'routing' => [
                    'ArnaudMoncondhuy\SynapseCore\Message\TestPresetMessage' => 'synapse_async',
                ],
            ];
        }

        $container->prependExtensionConfig('framework', $frameworkConfig);

        // 3. Auto-configuration du mapping Doctrine pour les entités du bundle.
        if ($container->hasExtension('doctrine')) {
            $alreadyMapped = false;
            foreach ($container->getExtensionConfig('doctrine') as $doctrineConfig) {
                if (isset($doctrineConfig['orm']['mappings']['SynapseBundle'])) {
                    $alreadyMapped = true;
                    break;
                }
            }

            if (!$alreadyMapped) {
                $container->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'mappings' => [
                            'SynapseCore' => [
                                'type'      => 'attribute',
                                'is_bundle' => false,
                                'dir'       => dirname(__DIR__, 2) . '/Storage/Entity',
                                'prefix'    => 'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity',
                                'alias'     => 'Synapse',
                            ],
                        ],
                    ],
                ]);
            }
        }
    }

    /**
     * Chargement principal de la configuration du bundle.
     *
     * @param array $configs configurations fusionnées
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // ── Personas ──────────────────────────────────────────────────────────
        $personasPath = $config['personas_path'] ?? (dirname(__DIR__) . '/Infrastructure/Resources/config/personas.json');
        // Fallback for vendor install
        if (!is_file($personasPath)) {
            $personasPath = dirname(__DIR__) . '/Resources/config/personas.json';
        }
        $container->setParameter('synapse.personas_path', $personasPath);

        // ── Persistence ───────────────────────────────────────────────────────
        $container->setParameter('synapse.persistence.enabled', $config['persistence']['enabled'] ?? false);
        $container->setParameter('synapse.persistence.conversation_class', $config['persistence']['conversation_class'] ?? null);
        $container->setParameter('synapse.persistence.message_class', $config['persistence']['message_class'] ?? null);

        // ── Encryption ────────────────────────────────────────────────────────
        $container->setParameter('synapse.encryption.enabled', $config['encryption']['enabled'] ?? false);
        $container->setParameter('synapse.encryption.key', $config['encryption']['key'] ?? null);

        // ── Token Tracking ────────────────────────────────────────────────────
        $container->setParameter('synapse.token_tracking.enabled', $config['token_tracking']['enabled'] ?? false);
        $container->setParameter('synapse.token_tracking.pricing', $config['token_tracking']['pricing'] ?? []);


        // ── Retention ─────────────────────────────────────────────────────────
        $container->setParameter('synapse.retention.days', $config['retention']['days'] ?? 30);

        // ── Security ──────────────────────────────────────────────────────────
        $container->setParameter('synapse.security.permission_checker', $config['security']['permission_checker'] ?? 'default');
        $container->setParameter('synapse.security.admin_role', $config['security']['admin_role'] ?? 'ROLE_ADMIN');
        $container->setParameter('synapse.security.api_csrf_enabled', $config['security']['api_csrf_enabled'] ?? true);

        // ── Context ───────────────────────────────────────────────────────────
        $container->setParameter('synapse.context.provider', $config['context']['provider'] ?? 'default');
        $container->setParameter('synapse.context.language', $config['context']['language'] ?? 'fr');
        $container->setParameter('synapse.context.base_identity', $config['context']['base_identity'] ?? null);

        // ── Admin ─────────────────────────────────────────────────────────────
        $container->setParameter('synapse.admin.enabled', $config['admin']['enabled'] ?? false);
        $container->setParameter('synapse.admin.route_prefix', $config['admin']['route_prefix'] ?? '/synapse/admin');
        $container->setParameter('synapse.admin.default_color', $config['admin']['default_color'] ?? '#8b5cf6');
        $container->setParameter('synapse.admin.default_icon', $config['admin']['default_icon'] ?? 'robot');

        // ── Version ──────────────────────────────────────────────────────────
        $versionFile = __DIR__ . '/../../../VERSION';
        $version = is_file($versionFile) ? trim(file_get_contents($versionFile)) : 'dev';
        $container->setParameter('synapse.version', $version);

        // ── UI ────────────────────────────────────────────────────────────────
        $container->setParameter('synapse.ui.sidebar_enabled', $config['ui']['sidebar_enabled'] ?? true);
        $container->setParameter('synapse.ui.layout_mode', $config['ui']['layout_mode'] ?? 'standalone');

        // ── Encryption Service ────────────────────────────────────────────────
        if ($config['encryption']['enabled']) {
            $container
                ->register('synapse.encryption_service', LibsodiumEncryptionService::class)
                ->setArguments([$config['encryption']['key']])
                ->setAutowired(true)
                ->setPublic(false);

            $container->setAlias(
                EncryptionServiceInterface::class,
                'synapse.encryption_service'
            );
        }




        // ── Chargement des services ───────────────────────────────────────────
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../../config'));

        // Load core services (always loaded)
        $loader->load('core.yaml');

        // Note: Admin services are loaded by SynapseAdminExtension (separate bundle)

        // ── ConversationManager Configuration ────────────────────────────────
        if ($container->hasDefinition(ConversationManager::class)) {
            $managerDef = $container->getDefinition(ConversationManager::class);

            if ($config['persistence']['enabled'] && !empty($config['persistence']['conversation_class'])) {
                $managerDef->setArgument('$conversationClass', $config['persistence']['conversation_class']);
                $managerDef->setArgument('$messageClass', $config['persistence']['message_class'] ?? null);
            }

            // Explicitly set encryption service if enabled to avoid autowiring gaps for optional params
            if ($config['encryption']['enabled']) {
                $managerDef->setArgument('$encryptionService', new Reference(EncryptionServiceInterface::class));
            }
        }

        // ── Auto-configuration (Tags automatiques) ────────────────────────────
        $container->registerForAutoconfiguration(AiToolInterface::class)
            ->addTag('synapse.tool');

        $container->registerForAutoconfiguration(ContextProviderInterface::class)
            ->addTag('synapse.context_provider');

        // ── Vector Store Configuration ────────────────────────────────────────
        // L'alias est désormais géré dynamiquement par DynamicVectorStore via core.yaml
        /*
        $vectorStoreAlias = match ($config['vector_store']['default'] ?? 'null') {
            'null' => 'ArnaudMoncondhuy\SynapseCore\Core\VectorStore\NullVectorStore',
            'in_memory' => 'ArnaudMoncondhuy\SynapseCore\Core\VectorStore\InMemoryVectorStore',
            'doctrine' => 'ArnaudMoncondhuy\SynapseCore\Core\VectorStore\DoctrineVectorStore',
            default => $config['vector_store']['default'],
        };

        $container->setAlias(
            \ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface::class,
            $vectorStoreAlias
        );
        */

        // ── Twig Globals ──────────────────────────────────────────────────────
        // Handled by SynapseAdminExtension if admin is enabled
    }

    /**
     * Retourne l'alias de l'extension pour la configuration YAML.
     * Permet aux utilisateurs d'utiliser `synapse:` au lieu de `synapse_extension:` dans config/packages/synapse.yaml.
     *
     * @return string L'alias 'synapse'
     */
    public function getAlias(): string
    {
        return 'synapse';
    }
}
