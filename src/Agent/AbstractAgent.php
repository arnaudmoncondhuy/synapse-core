<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent;

use ArnaudMoncondhuy\SynapseCore\Contract\AgentInterface;

/**
 * Classe de base recommandée pour tout agent code (bundle ou app hôte).
 *
 * ## Pourquoi étendre cette classe plutôt qu'implémenter AgentInterface directement ?
 *
 * `AgentInterface::call()` accepte `$options['context']` de façon optionnelle pour
 * rester compatible avec `symfony/ai`. En pratique, un agent sans `AgentContext` perd :
 * - La traçabilité (requestId, userId, debug logs)
 * - La protection contre les boucles infinies (profondeur max)
 * - Le suivi de budget tokens
 *
 * Cette classe rend le contexte **obligatoire** via un `call()` final qui délègue
 * à la méthode `execute()` que l'implémentation doit définir.
 *
 * ## Usage correct (app hôte)
 *
 * ```php
 * final class MonAgent extends AbstractAgent
 * {
 *     public function getName(): string { return 'mon_agent'; }
 *     public function getDescription(): string { return '...'; }
 *
 *     protected function execute(Input $input, AgentContext $context): Output
 *     {
 *         // Logique métier ici
 *     }
 * }
 * ```
 *
 * ## Invocation correcte (toujours via AgentResolver)
 *
 * ```php
 * $ctx   = $this->agentResolver->createRootContext(userId: $user->getUserIdentifier());
 * $agent = $this->agentResolver->resolve('mon_agent', $ctx);
 * $out   = $agent->call(Input::ofMessage('…'), ['context' => $ctx]);
 * ```
 *
 * ## Ce que cette classe ne peut PAS bloquer
 *
 * L'injection directe du service PHP reste possible côté Symfony. Cette classe
 * ne peut donc que lever une exception à l'exécution si `call()` est appelé sans
 * contexte. La protection à la compilation nécessiterait un analyseur statique
 * (PHPStan custom rule).
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

    /**
     * Libellé lisible par un humain — conversion snake_case → Title Case par défaut.
     *
     * Surchargez pour un libellé plus précis :
     * ```php
     * public function getLabel(): string { return 'Générateur d\'images Flash Info'; }
     * ```
     */
    public function getLabel(): string
    {
        return ucwords(str_replace('_', ' ', $this->getName()));
    }

    /**
     * Logique métier de l'agent — à implémenter dans chaque sous-classe.
     *
     * Le contexte est garanti non-null ici. Utilisez-le pour :
     * - Passer aux appels ChatService (`['agent_run_id' => $context->getRequestId()]`)
     * - Créer un contexte enfant si cet agent en appelle un autre (`$context->createChild(...)`)
     */
    abstract protected function execute(Input $input, AgentContext $context): Output;
}
