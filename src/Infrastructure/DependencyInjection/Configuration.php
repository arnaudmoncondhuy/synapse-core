<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Définition de l'arbre de configuration du Bundle.
 *
 * Ce fichier valide et documente les options disponibles dans `config/packages/synapse.yaml`.
 *
 * Configuration minimale (tout est géré en DB via l'admin Synapse) :
 * synapse:
 *     persistence:
 *         enabled: true
 *         conversation_class: 'App\...\Entity\SynapseConversation'
 *         message_class: 'App\...\Entity\SynapseMessage'
 *     admin:
 *         enabled: true
 *
 * Providers, credentials et presets LLM (temperature, model, thinking…)
 * sont gérés dynamiquement via l'admin Synapse → aucun YAML requis après install.
 */
class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('synapse');

        $treeBuilder->getRootNode()
            ->children()

            ->scalarNode('personas_path')
            ->defaultNull()
            ->info('Chemin absolu vers votre fichier personas.json personnalisé. Si null, utilise le fichier par défaut du bundle.')
            ->end()

            // ── Persistence ───────────────────────────────────────────────────
            ->arrayNode('persistence')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultTrue()
            ->info('Activer la persistence des conversations en base de données (Doctrine)')
            ->end()
            ->scalarNode('conversation_class')
            ->defaultNull()
            ->info('FQCN de l\'entité SynapseConversation concrète (ex : App\Module\Assistant\Entity\SynapseConversation)')
            ->end()
            ->scalarNode('message_class')
            ->defaultNull()
            ->info('FQCN de l\'entité SynapseMessage concrète (ex : App\Module\Assistant\Entity\SynapseMessage)')
            ->end()
            ->end()
            ->end()


            // ── Encryption ────────────────────────────────────────────────────
            ->arrayNode('encryption')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultFalse()
            ->info('Activer le chiffrement des conversations (libsodium AES-256-GCM)')
            ->end()
            ->scalarNode('key')
            ->defaultNull()
            ->info('Clé de chiffrement (32 bytes, ex: %env(SYNAPSE_ENCRYPTION_KEY)%)')
            ->end()
            ->end()
            ->end()

            // ── Token Tracking ────────────────────────────────────────────────
            ->arrayNode('token_tracking')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultFalse()
            ->info('Activer le tracking des tokens et calcul des coûts')
            ->end()
            ->arrayNode('pricing')
            ->useAttributeAsKey('model')
            ->arrayPrototype()
            ->children()
            ->floatNode('input')->end()
            ->floatNode('output')->end()
            ->end()
            ->end()
            ->info('Tarifs par modèle ($/1M tokens) ex: gemini-2.5-flash: {input: 0.30, output: 2.50}')
            ->end()
            ->end()
            ->end()

            // ── Retention ─────────────────────────────────────────────────────
            ->arrayNode('retention')
            ->addDefaultsIfNotSet()
            ->children()
            ->integerNode('days')
            ->defaultValue(30)
            ->min(1)
            ->info('Durée de rétention RGPD (jours)')
            ->end()
            ->end()
            ->end()

            // ── Security ──────────────────────────────────────────────────────
            ->arrayNode('security')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('permission_checker')
            ->defaultValue('default')
            ->info('Stratégie de vérification des permissions : "default", "none", ou service ID personnalisé')
            ->end()
            ->scalarNode('admin_role')
            ->defaultValue('ROLE_ADMIN')
            ->info('Rôle Symfony requis pour accéder à l\'admin et voir toutes les conversations')
            ->end()
            ->booleanNode('api_csrf_enabled')
            ->defaultTrue()
            ->info('Activer la vérification CSRF sur les endpoints /synapse/api/*. Mettre à false en dernier recours si le token ne peut pas être fourni (cache, surcharge template).')
            ->end()
            ->end()
            ->end()

            // ── Context ───────────────────────────────────────────────────────
            ->arrayNode('context')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('provider')
            ->defaultValue('default')
            ->info('Fournisseur de contexte : "default", "user_aware", ou service ID personnalisé')
            ->end()
            ->scalarNode('language')
            ->defaultValue('fr')
            ->info('Langue par défaut pour les prompts système (fr, en)')
            ->end()
            ->scalarNode('base_identity')
            ->defaultNull()
            ->info('Identité de base personnalisée (override la valeur par défaut)')
            ->end()
            ->end()
            ->end()

            // ── Admin ─────────────────────────────────────────────────────────
            ->arrayNode('admin')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultFalse()
            ->info('Activer l\'interface d\'administration')
            ->end()
            ->scalarNode('route_prefix')
            ->defaultValue('/synapse/admin')
            ->info('Préfixe des routes admin')
            ->end()
            ->scalarNode('default_color')
            ->defaultValue('#8b5cf6')
            ->info('Couleur par défaut du module (hex)')
            ->end()
            ->scalarNode('default_icon')
            ->defaultValue('robot')
            ->info('Icône par défaut du module')
            ->end()
            ->end()
            ->end()

            // ── UI ────────────────────────────────────────────────────────────
            ->arrayNode('ui')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('sidebar_enabled')
            ->defaultTrue()
            ->info('Activer la sidebar (historique conversations)')
            ->end()
            ->scalarNode('layout_mode')
            ->defaultValue('standalone')
            ->info('Mode de layout admin : "standalone" (avec sidebar) ou "module"')
            ->end()
            ->end()
            ->end()

            // ── Vector Store ──────────────────────────────────────────────────
            ->arrayNode('vector_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('default')
            ->defaultValue('null')
            ->info('L\'implémentation par défaut : "null", "in_memory", "doctrine" ou un service ID personnalisé.')
            ->end()
            ->end()
            ->end()

            ->end();

        return $treeBuilder;
    }
}
