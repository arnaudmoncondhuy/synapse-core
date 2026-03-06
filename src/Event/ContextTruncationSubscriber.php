<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Engine\ContextTruncationService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber interceptant le prompt avant son envoi au LLM
 * pour appliquer la troncature de contexte (Fenêtre Glissante).
 */
class ContextTruncationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ContextTruncationService $truncationService,
        private ModelCapabilityRegistry $capabilityRegistry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité basse (-50) pour s'exécuter après la construction complète du contexte
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', -50],
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $config = $event->getConfig();
        $modelNameMixed = $config['model'] ?? null;
        $modelName = is_string($modelNameMixed) ? $modelNameMixed : null;

        if (!$modelName) {
            return;
        }

        // Récupération de la capacité "Context Window" du modèle
        $capabilities = $this->capabilityRegistry->getCapabilities($modelName);
        $contextWindow = $capabilities->contextWindow;

        // Si le modèle n'a pas de limite définie, on ne tronque pas
        if (null === $contextWindow || $contextWindow <= 0) {
            return;
        }

        $prompt = $event->getPrompt();
        $messagesRaw = $prompt['contents'] ?? [];
        $messages = is_array($messagesRaw) ? $messagesRaw : [];

        if (empty($messages)) {
            return;
        }

        /** @var array<int, array<string, mixed>> $typedMessages */
        $typedMessages = $messages;
        $truncatedMessages = $this->truncationService->truncate($typedMessages, $contextWindow);

        // Remplacement des messages dans l'événement
        $prompt['contents'] = $truncatedMessages;
        $event->setPrompt($prompt);
    }
}
