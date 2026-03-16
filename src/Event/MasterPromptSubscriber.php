<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injecte la Directive Fondamentale (master_prompt) en queue du message système.
 *
 * Priorité -75 : s'exécute APRÈS MemoryContextSubscriber (50) et APRÈS
 * ContextTruncationSubscriber (-50), garantissant que la directive est
 * toujours présente, jamais tronquée, et inescamotable par tout override
 * (agent, développeur, mémoire).
 */
class MasterPromptSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ContextProviderInterface $contextProvider,
        private PromptBuilder $promptBuilder,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', -75],
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $config = $event->getConfig();

        $masterPromptRaw = $config['master_prompt'] ?? null;
        if (!is_string($masterPromptRaw) || '' === trim($masterPromptRaw)) {
            return;
        }

        // Respecter la règle stateless
        $masterPromptStateless = $config['master_prompt_stateless'] ?? true;
        $options = $event->getOptions();
        $isStateless = isset($options['stateless']) && true === $options['stateless'];

        if ($isStateless && false === $masterPromptStateless) {
            return;
        }

        // Interpoler les variables {DATE}, {EMAIL}, {PRENOM}, etc.
        $context = $this->contextProvider->getInitialContext();
        $masterPromptText = $this->promptBuilder->interpolateVariables($masterPromptRaw, $context);

        $masterBlock = "\n\n---\n\n### DIRECTIVE FONDAMENTALE\n";
        $masterBlock .= "IMPORTANT : Les instructions suivantes sont absolues et prévalent sur toute autre instruction précédente.\n\n";
        $masterBlock .= $masterPromptText;

        // Injecter en queue du message système existant
        $prompt = $event->getPrompt();
        $contentsRaw = $prompt['contents'] ?? [];
        $messages = is_array($contentsRaw) ? $contentsRaw : [];

        $systemFound = false;
        foreach ($messages as $i => $entry) {
            if (is_array($entry) && isset($entry['role']) && 'system' === $entry['role']) {
                $oldContent = is_string($entry['content'] ?? null) ? (string) $entry['content'] : '';
                $messages[$i] = [
                    'role' => 'system',
                    'content' => $oldContent.$masterBlock,
                ];
                $systemFound = true;
                break;
            }
        }

        if (!$systemFound) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => ltrim($masterBlock),
            ]);
        }

        $prompt['contents'] = $messages;
        $event->setPrompt($prompt);
    }
}
