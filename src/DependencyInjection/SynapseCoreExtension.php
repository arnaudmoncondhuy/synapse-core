<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\DependencyInjection;

use ArnaudMoncondhuy\SynapseCore\Contract\AiToolInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderFactoryInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\RagSourceProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Security\LibsodiumEncryptionService;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

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

        // Note: Core has no assets directory - all assets are in Admin and Chat bundles
        $frameworkConfig = [];

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

        if (!empty($frameworkConfig)) {
            $container->prependExtensionConfig('framework', $frameworkConfig);
        }

        // 3. Auto-configuration du mapping Doctrine pour les entités du bundle.
        if ($container->hasExtension('doctrine')) {
            $alreadyMapped = false;
            foreach ($container->getExtensionConfig('doctrine') as $doctrineConfig) {
                if (
                    is_array($doctrineConfig)
                    && isset($doctrineConfig['orm']) && is_array($doctrineConfig['orm'])
                    && isset($doctrineConfig['orm']['mappings']) && is_array($doctrineConfig['orm']['mappings'])
                    && isset($doctrineConfig['orm']['mappings']['SynapseBundle'])
                ) {
                    $alreadyMapped = true;
                    break;
                }
            }

            if (!$alreadyMapped) {
                $container->prependExtensionConfig('doctrine', [
                    'orm' => [
                        'mappings' => [
                            'SynapseCore' => [
                                'type' => 'attribute',
                                'is_bundle' => false,
                                'dir' => \dirname(__DIR__).'/Storage/Entity',
                                'prefix' => 'ArnaudMoncondhuy\\SynapseCore\\Storage\\Entity',
                                'alias' => 'Synapse',
                            ],
                        ],
                    ],
                ]);
            }
        }

        // 4. Configuration des traductions pour le Core.
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'translator' => [
                    'default_path' => '%kernel.project_dir%/translations',
                    'fallbacks' => ['fr', 'en'],
                    'paths' => [
                        \dirname(__DIR__, 2).'/translations',
                    ],
                ],
            ]);
        }
    }

    /**
     * Chargement principal de la configuration du bundle.
     *
     * @param array<mixed, mixed> $configs configurations fusionnées
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        // ── Persistence ───────────────────────────────────────────────────────
        $container->setParameter('synapse.persistence.conversation_class', $config['persistence']['conversation_class']);
        $container->setParameter('synapse.persistence.message_class', $config['persistence']['message_class']);

        // ── Encryption ────────────────────────────────────────────────────────
        $container->setParameter('synapse.encryption.enabled', $config['encryption']['enabled'] ?? false);
        $container->setParameter('synapse.encryption.key', $config['encryption']['key'] ?? null);

        // ── Security ──────────────────────────────────────────────────────────
        $container->setParameter('synapse.security.admin_role', $config['security']['admin_role'] ?? 'ROLE_ADMIN');
        $container->setParameter('synapse.security.chat_role', $config['security']['chat_role'] ?? 'ROLE_USER');
        $container->setParameter('synapse.security.api_csrf_enabled', $config['security']['api_csrf_enabled'] ?? true);

        // ── Context ───────────────────────────────────────────────────────────
        $container->setParameter('synapse.context.language', $config['context']['language'] ?? 'fr');

        // ── Token tracking ────────────────────────────────────────────────────
        $container->setParameter('synapse.token_tracking.enabled', $config['token_tracking']['enabled'] ?? false);
        $container->setParameter('synapse.token_tracking.reference_currency', $config['token_tracking']['reference_currency'] ?? 'EUR');
        $container->setParameter('synapse.token_tracking.currency_rates', $config['token_tracking']['currency_rates'] ?? []);
        $container->setParameter('synapse.token_tracking.sliding_day_hours', (int) ($config['token_tracking']['sliding_day_hours'] ?? 4));

        // ── Routing ───────────────────────────────────────────────────────────
        $container->setParameter('synapse.admin_prefix', $config['routing']['admin_prefix'] ?? '/synapse/admin');
        $container->setParameter('synapse.chat_ui_prefix', $config['routing']['chat_ui_prefix'] ?? '/synapse/chat');
        $container->setParameter('synapse.chat_api_prefix', $config['routing']['chat_api_prefix'] ?? '/synapse/api');
        $container->setParameter('synapse.admin_outer_layout', $config['routing']['admin_outer_layout'] ?? null);

        // ── Version ──────────────────────────────────────────────────────────
        $versionFile = __DIR__.'/../../../VERSION';
        $version = is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : 'dev';
        $container->setParameter('synapse.version', $version);

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
        $loader = new YamlFileLoader($container, new FileLocator(\dirname(__DIR__, 1).'/../config'));

        // Load core services (always loaded)
        $loader->load('core.yaml');

        // Note: Admin services are loaded by SynapseAdminExtension (separate bundle)

        // ── ConversationManager Configuration ────────────────────────────────
        if ($container->hasDefinition(ConversationManager::class)) {
            $managerDef = $container->getDefinition(ConversationManager::class);

            if (!empty($config['persistence']['conversation_class'])) {
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

        $container->registerForAutoconfiguration(RagSourceProviderInterface::class)
            ->addTag('synapse.rag_source');

        $container->registerForAutoconfiguration(RagSourceProviderFactoryInterface::class)
            ->addTag('synapse.rag_source_factory');

        // ── Vector Store Configuration ────────────────────────────────────────
        // L'alias est désormais géré dynamiquement par DynamicVectorStore via core.yaml
        /*
        $vectorStoreAlias = match ($config['vector_store']['default'] ?? 'null') {
            'null' => 'ArnaudMoncondhuy\SynapseCore\VectorStore\NullVectorStore',
            'in_memory' => 'ArnaudMoncondhuy\SynapseCore\VectorStore\InMemoryVectorStore',
            'doctrine' => 'ArnaudMoncondhuy\SynapseCore\VectorStore\DoctrineVectorStore',
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
