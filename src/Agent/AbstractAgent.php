<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;

/**
 * Classe de base recommandée pour tout agent code (bundle ou app hôte).
 *
 * ## Pourquoi étendre cette classe plutôt qu'implémenter AgentInterface directement ?
 *
 * 1. **Contexte obligatoire** : `call()` est `final` et exige un {@see AgentContext}.
 * 2. **Pipeline-aware** : les méthodes `getSystemPrompt()`, `getAllowedToolNames()`,
 *    `getPresetKey()`, `getToneKey()`, `getEmoji()` sont lues par le pipeline
 *    ({@see \ArnaudMoncondhuy\SynapseCore\Event\ContextBuilderSubscriber}) pour injecter
 *    automatiquement le prompt, les outils et le preset — même traitement qu'un agent DB.
 * 3. **Helper `buildAskOptions()`** : construit les options à passer à `ChatService::ask()`
 *    avec l'identification de l'agent (traçabilité, coûts, debug).
 *
 * ## Usage (app hôte)
 *
 * ```php
 * final class MonAgent extends AbstractAgent
 * {
 *     public function getName(): string { return 'mon_agent'; }
 *     public function getDescription(): string { return 'Mon agent.'; }
 *
 *     public function getSystemPrompt(): string
 *     {
 *         return 'Tu es un assistant spécialisé dans...';
 *     }
 *
 *     protected function execute(Input $input, AgentContext $context): Output
 *     {
 *         $result = $this->chatService->ask(
 *             $input->getMessage(),
 *             $this->buildAskOptions(['stateless' => true]),
 *         );
 *         return Output::fromChatServiceResult($result);
 *     }
 * }
 * ```
 *
 * ## Mode orchestrateur
 *
 * Un agent qui gère son propre prompt (ex: {@see PresetValidator\PresetValidatorAgent})
 * laisse `getSystemPrompt()` retourner `''` — le pipeline ne touche pas au prompt.
 * L'agent reste tracé (coûts, debug) mais contrôle entièrement ce qu'il envoie au LLM.
 */
abstract class AbstractAgent implements AgentInterface
{
    /**
     * Point d'entrée unifié — NE PAS surcharger.
     *
     * Extrait et valide l'`AgentContext` depuis `$options['context']`, puis
     * délègue à {@see execute()}. Lève une `\LogicException` explicite si le
     * contexte est absent pour guider immédiatement le développeur.
     *
     * @param array<string, mixed> $options Doit contenir la clé `'context'` avec un {@see AgentContext}
     *
     * @throws \LogicException si `$options['context']` est absent ou n'est pas un AgentContext
     */
    final public function call(Input $input, array $options = []): Output
    {
        $context = $options['context'] ?? null;

        if (!$context instanceof AgentContext) {
            throw new \LogicException(sprintf('Agent "%s" appelé sans AgentContext. Passez toujours par AgentResolver :'."\n".'  $ctx   = $resolver->createRootContext(userId: $user->getUserIdentifier());'."\n".'  $agent = $resolver->resolve(\'%s\', $ctx);'."\n".'  $out   = $agent->call($input, [\'context\' => $ctx]);'."\n".'Injecter l\'agent directement et appeler call() sans contexte perd la traçabilité et les garde-fous.', $this->getName(), $this->getName()));
        }

        return $this->execute($input, $context);
    }

    // ─── Pipeline-aware : propriétés lues par ContextBuilderSubscriber ─────

    /**
     * Prompt système injecté par le pipeline quand cet agent est invoqué.
     *
     * Retourne `''` par défaut (mode orchestrateur : le pipeline ne touche pas au prompt).
     * Surchargez pour définir le comportement de l'agent :
     *
     * ```php
     * public function getSystemPrompt(): string
     * {
     *     return 'Tu es un agent expert en analyse de documents...';
     * }
     * ```
     */
    public function getSystemPrompt(): string
    {
        return '';
    }

    /**
     * Noms des outils (function calling) autorisés pour cet agent.
     *
     * Retourne `[]` par défaut = tous les outils disponibles.
     * Surchargez pour restreindre :
     *
     * ```php
     * public function getAllowedToolNames(): array { return ['web_search', 'calculator']; }
     * ```
     *
     * @return list<string>
     */
    public function getAllowedToolNames(): array
    {
        return [];
    }

    /**
     * Clé du preset LLM à utiliser (configuré dans l'admin Synapse).
     *
     * Retourne `null` par défaut = utilise le preset actif global.
     */
    public function getPresetKey(): ?string
    {
        return null;
    }

    /**
     * Clé du ton de réponse à appliquer.
     *
     * Retourne `null` par défaut = pas de ton spécifique.
     */
    public function getToneKey(): ?string
    {
        return null;
    }

    /**
     * Emoji affiché dans l'admin et les debug logs.
     */
    public function getEmoji(): string
    {
        return "\u{1F916}";
    }

    /**
     * Libellé lisible par un humain — conversion snake_case → Title Case par défaut.
     */
    public function getLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->getName()));
    }

    // ─── Helper pour les appels ChatService ───────────────────────────────

    /**
     * Construit les options à passer à `ChatService::ask()` avec l'identification
     * de l'agent. Garantit que chaque appel LLM est tracé, débogable et comptabilisé.
     *
     * ```php
     * $result = $this->chatService->ask($prompt, $this->buildAskOptions([
     *     'stateless' => true,
     * ]));
     * ```
     *
     * @param array<string, mixed> $overrides Options supplémentaires (stateless, tools, etc.)
     *
     * @return array<string, mixed>
     */
    protected function buildAskOptions(array $overrides = []): array
    {
        return array_merge([
            'agent' => $this->getName(),
            'module' => 'agent',
            'action' => 'agent_call',
        ], $overrides);
    }

    // ─── Logique métier (à implémenter) ───────────────────────────────────

    /**
     * Logique métier de l'agent — à implémenter dans chaque sous-classe.
     *
     * Le contexte est garanti non-null ici. Utilisez `$this->buildAskOptions()`
     * pour construire les options de chaque appel `ChatService::ask()`.
     */
    abstract protected function execute(Input $input, AgentContext $context): Output;
}
