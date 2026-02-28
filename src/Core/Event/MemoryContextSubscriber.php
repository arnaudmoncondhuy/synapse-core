<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Memory\MemoryManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Injecte silencieusement les souvenirs de l'utilisateur dans le contexte avant l'envoi au LLM.
 *
 * Écoute SynapsePrePromptEvent avec priorité 50 (après ContextBuilderSubscriber à 100),
 * afin d'ajouter les faits mémorisés après que l'historique et le système de base aient été construits.
 */
class MemoryContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoryManager $memoryManager,
        private ?TokenStorageInterface $tokenStorage = null,
        private int $maxMemories = 5
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', 50], // Priorité 50 < ContextBuilderSubscriber (100)
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $userId = $this->getCurrentUserId();

        if (!$userId) {
            return; // Pas d'utilisateur connecté → pas de mémoire à injecter
        }

        $message = $event->getMessage();

        if (empty($message)) {
            return;
        }

        try {
            $memories = $this->memoryManager->recall($message, $userId, $this->maxMemories);
        } catch (\Throwable) {
            return; // Fail silencieusement (pas de provider d'embedding configuré, etc.)
        }

        if (empty($memories)) {
            return;
        }

        // Filtrer les résultats trop peu pertinents (seuil de similarité : 0.7)
        $relevant = array_filter($memories, fn($m) => $m['score'] >= 0.7);

        if (empty($relevant)) {
            return;
        }

        // Construire le bloc "mémoire" à injecter dans le système prompt
        $memoryLines = array_map(
            fn($m) => '- ' . $m['content'],
            array_values($relevant)
        );

        $memoryBlock = implode("\n", $memoryLines);

        $systemMemoryMessage = [
            'role'    => 'system',
            'content' => "Informations connues sur l'utilisateur (mémorisées lors des conversations précédentes) :\n{$memoryBlock}\n\nUtilise ces informations si elles sont pertinentes pour répondre, sans les mentionner explicitement à moins que l'utilisateur ne le demande."
        ];

        // Injecter après le system prompt de base et avant le premier message utilisateur
        $contents = $event->getPrompt();

        // Trouver la position après le(s) message(s) system
        $insertAt = 0;
        foreach ($contents as $i => $entry) {
            if (($entry['role'] ?? '') === 'system') {
                $insertAt = $i + 1;
            } else {
                break;
            }
        }

        array_splice($contents, $insertAt, 0, [$systemMemoryMessage]);
        $event->setPrompt($contents);
    }

    private function getCurrentUserId(): ?string
    {
        if (!$this->tokenStorage) {
            return null;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof ConversationOwnerInterface) {
            return null;
        }

        $id = $user->getId();
        return $id !== null ? (string) $id : null;
    }
}
