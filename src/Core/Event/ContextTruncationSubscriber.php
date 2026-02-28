<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use ArnaudMoncondhuy\SynapseCore\Core\Chat\ContextTruncationService;
use ArnaudMoncondhuy\SynapseCore\Core\Chat\ModelCapabilityRegistry;
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
    ) {}

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
        $modelName = $config['model'] ?? null;

        if (!$modelName) {
            return;
        }

        // Récupération de la capacité "Context Window" du modèle
        $capabilities = $this->capabilityRegistry->getCapabilities($modelName);
        $contextWindow = $capabilities->contextWindow;

        // Si le modèle n'a pas de limite définie, on ne tronque pas
        if ($contextWindow === null || $contextWindow <= 0) {
            return;
        }

        $messages = $event->getPrompt();
        // $messages = $prompt['contents'] ?? []; // Ancien accès erroné

        if (empty($messages)) {
            return;
        }

        $truncatedMessages = $this->truncationService->truncate($messages, $contextWindow);

        // Remplacement des messages dans l'événement
        $event->setPrompt($truncatedMessages);
    }
}
