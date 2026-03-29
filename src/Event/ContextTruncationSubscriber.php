<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Engine\ContextTruncationService;
use ArnaudMoncondhuy\SynapseCore\Engine\ModelCapabilityRegistry;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptOptimizeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscriber interceptant le prompt avant son envoi au LLM
 * pour appliquer la troncature de contexte (Fenêtre Glissante).
 */
class ContextTruncationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ContextTruncationService $truncationService,
        private readonly ModelCapabilityRegistry $capabilityRegistry,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priorité basse (-50) pour s'exécuter après la construction complète du contexte
        return [
            PromptOptimizeEvent::class => ['onPrePrompt', 0],
        ];
    }

    public function onPrePrompt(PromptOptimizeEvent $event): void
    {
        $config = $event->getConfig();
        $modelName = $config?->model ?: null;

        if (!$modelName) {
            return;
        }

        // Récupération de la capacité "Context Window" du modèle
        // getEffectiveMaxInputTokens() utilise maxInputTokens en priorité, puis contextWindow en fallback
        $capabilities = $this->capabilityRegistry->getCapabilities($modelName);
        $contextWindow = $capabilities->getEffectiveMaxInputTokens();

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
