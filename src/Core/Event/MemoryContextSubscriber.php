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

        $memoryString = "\n\n---\n\n### 🧠 MÉMOIRE ET CONTEXTE UTILISATEUR\n";
        $memoryString .= "Les informations suivantes ont été mémorisées lors de conversations précédentes avec l'utilisateur :\n{$memoryBlock}\n";
        $memoryString .= "Instruction: Utilise ces informations de manière naturelle si elles sont pertinentes pour répondre, mais ne dis jamais explicitement 'd'après mes souvenirs' ou 'je me souviens que'. Agis simplement en tenant compte de ce contexte.";

        $prompt = $event->getPrompt();
        $messages = $prompt['contents'] ?? [];

        // Chercher le premier message 'system' pour y concaténer la mémoire
        $systemFound = false;
        foreach ($messages as $i => $entry) {
            if (($entry['role'] ?? '') === 'system') {
                $messages[$i]['content'] .= $memoryString;
                $systemFound = true;
                break;
            }
        }

        // Cas de fallback (anormal mais géré) où aucun message système n'existerait
        if (!$systemFound) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => ltrim($memoryString)
            ]);
        }

        $prompt['contents'] = $messages;
        $event->setPrompt($prompt);
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
