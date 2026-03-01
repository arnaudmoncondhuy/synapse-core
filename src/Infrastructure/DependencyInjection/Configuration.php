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
 * Configuration minimale :
 * synapse:
 *     persistence:
 *         enabled: true
 *         conversation_class: 'App\Entity\SynapseConversation'
 *         message_class: 'App\Entity\SynapseMessage'
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
            ->info('FQCN de l\'entité SynapseConversation concrète (ex : App\Entity\SynapseConversation)')
            ->end()
            ->scalarNode('message_class')
            ->defaultNull()
            ->info('FQCN de l\'entité SynapseMessage concrète (ex : App\Entity\SynapseMessage)')
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

            // ── Security ──────────────────────────────────────────────────────
            ->arrayNode('security')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('admin_role')
            ->defaultValue('ROLE_ADMIN')
            ->info('Rôle Symfony requis pour accéder à l\'admin (/synapse/admin*)')
            ->end()
            ->scalarNode('chat_role')
            ->defaultValue('ROLE_USER')
            ->info('Rôle Symfony requis pour accéder au chat et aux API (/synapse/chat, /synapse/api/*). PUBLIC_ACCESS pour désactiver.')
            ->end()
            ->booleanNode('api_csrf_enabled')
            ->defaultTrue()
            ->info('Activer la vérification CSRF sur les endpoints /synapse/api/*. Mettre à false en dernier recours si le token ne peut pas être fourni.')
            ->end()
            ->end()
            ->end()

            // ── Context ───────────────────────────────────────────────────────
            ->arrayNode('context')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('language')
            ->defaultValue('fr')
            ->info('Langue par défaut pour les prompts système (fr, en)')
            ->end()
            ->end()
            ->end()

            // ── Vector Store ──────────────────────────────────────────────────
            ->arrayNode('vector_store')
            ->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('default')
            ->defaultValue('null')
            ->info('Implémentation par défaut : "null", "in_memory", "doctrine" ou un service ID personnalisé.')
            ->end()
            ->end()
            ->end()

            // ── Token tracking & spending (devises, plafonds) ─────────────────
            ->arrayNode('token_tracking')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->defaultFalse()
            ->info('Activer le tracking des tokens et le calcul des coûts')
            ->end()
            ->scalarNode('reference_currency')
            ->defaultValue('EUR')
            ->info('Devise de référence pour les plafonds et agrégats (ex. EUR, USD)')
            ->end()
            ->arrayNode('currency_rates')
            ->info('Taux de conversion vers la devise de référence (clé = devise source, valeur = taux vers reference_currency)')
            ->example(['USD' => 0.92, 'GBP' => 1.17])
            ->scalarPrototype()->end()
            ->end()
            ->integerNode('sliding_day_hours')
            ->defaultValue(4)
            ->min(1)->max(8760)
            ->info('Durée en heures de la fenêtre glissante "sliding_day" (défaut : 4h)')
            ->end()
            ->end()
            ->end()

            ->end();

        return $treeBuilder;
    }
}
