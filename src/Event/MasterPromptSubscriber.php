<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ContextProviderInterface;
use ArnaudMoncondhuy\SynapseCore\Engine\PromptBuilder;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptFinalizeEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\PromptUtil;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Injecte la Directive Fondamentale (master_prompt) en queue du message système.
 *
 * Phase FINALIZE — s'exécute après ENRICH (mémoire, RAG) et OPTIMIZE (troncation),
 * garantissant que la directive est toujours présente, jamais tronquée,
 * et inescamotable par tout override (agent, développeur, mémoire).
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
            PromptFinalizeEvent::class => ['onPrePrompt', 0],
        ];
    }

    public function onPrePrompt(PromptFinalizeEvent $event): void
    {
        $config = $event->getConfig();

        $masterPromptRaw = $config?->masterPrompt;
        if (!is_string($masterPromptRaw) || '' === trim($masterPromptRaw)) {
            return;
        }

        // Respecter la règle stateless
        $masterPromptStateless = $config->masterPromptStateless;
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

        $prompt['contents'] = PromptUtil::appendToSystemMessage($messages, $masterBlock);
        $event->setPrompt($prompt);
    }
}
