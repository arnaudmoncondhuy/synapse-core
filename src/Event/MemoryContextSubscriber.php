<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private int $maxMemories = 5,
        private ?SynapseProfiler $profiler = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', 50], // Priorité 50 < ContextBuilderSubscriber (100)
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $options = $event->getOptions();
        $userIdMixed = $options['user_id'] ?? $this->getCurrentUserId();
        $userId = is_string($userIdMixed) ? $userIdMixed : null;

        $message = $event->getMessage();
        if (empty($message)) {
            return;
        }

        $conversationIdMixed = $options['conversation_id'] ?? null;
        $conversationId = is_string($conversationIdMixed) ? $conversationIdMixed : null;
        $error = null;
        $memories = [];

        if (!$userId) {
            $error = $this->translator 
                ? $this->translator->trans('synapse.core.memory.error.user_not_identified', [], 'synapse_core')
                : "Impossible d'injecter la mémoire : utilisateur non identifié (anonyme).";
        } else {
            try {
                if ($this->profiler) {
                    $profileTitle = $this->translator ? $this->translator->trans('synapse.core.memory.profile.title', [], 'synapse_core') : 'PgVector Memory Search';
                    $profileDesc = $this->translator ? $this->translator->trans('synapse.core.memory.profile.description', [], 'synapse_core') : "Calcul d'embedding du message utilisateur et recherche cosinus des entrées similaires dans la base de données PostgreSQL.";
                    $this->profiler->start('Memory', $profileTitle, $profileDesc);
                }

                $memories = $this->memoryManager->recall($message, $userId, $conversationId, $this->maxMemories);

                if ($this->profiler) {
                    $this->profiler->stop('Memory', 'PgVector Memory Search', 0);
                }
            } catch (\Throwable $e) {
                if ($this->profiler) {
                    $this->profiler->stop('Memory', 'PgVector Memory Search', 0);
                }
                $error = $e->getMessage();
            }
        }

        $prompt = $event->getPrompt();
        $metadata = is_array($prompt['metadata'] ?? null) ? $prompt['metadata'] : [];

        if (empty($memories)) {
            $metadata['memory_matching'] = [
                'found' => 0,
                'relevant' => 0,
                'threshold' => 0.4,
                'details' => [],
                'error' => $error ?? null,
            ];
            $prompt['metadata'] = $metadata;
            $event->setPrompt($prompt);

            return;
        }

        // Filtrer les résultats trop peu pertinents (seuil de similarité abaissé pour tolérance)
        $relevant = array_filter($memories, fn ($m) => $m['score'] >= 0.4);

        // Ajout des informations de matching dans les metadata du prompt pour le Debug
        $metadata['memory_matching'] = [
            'found' => count($memories),
            'relevant' => count($relevant),
            'threshold' => 0.4,
            'details' => array_map(fn ($m) => [
                'score' => $m['score'],
                'content' => substr($m['content'], 0, 50).'...',
            ], $memories),
            'error' => null,
        ];
        $prompt['metadata'] = $metadata;

        if (empty($relevant)) {
            $event->setPrompt($prompt);

            return;
        }

        // Construire le bloc "mémoire" à injecter dans le système prompt
        $memoryLines = array_map(
            fn ($m) => '- '.$m['content'],
            array_values($relevant)
        );

        $memoryBlock = implode("\n", $memoryLines);

        $memoryString = "\n\n---\n\n";
        if ($this->translator) {
            $memoryString .= $this->translator->trans('synapse.core.prompt.memory_block_header', [
                '%memories%' => $memoryBlock,
            ], 'synapse_core');
        } else {
            $memoryString .= "### 🧠 MÉMOIRE ET CONTEXTE UTILISATEUR\n";
            $memoryString .= "Les informations suivantes ont été mémorisées lors de conversations précédentes avec l'utilisateur :\n{$memoryBlock}\n";
            $memoryString .= "Instruction: Utilise ces informations de manière naturelle si elles sont pertinentes pour répondre, mais ne dis jamais explicitement 'd'après mes souvenirs' ou 'je me souviens que'. Agis simplement en tenant compte de ce contexte.";
        }

        $contentsRaw = $prompt['contents'] ?? [];
        $messages = is_array($contentsRaw) ? $contentsRaw : [];

        // Chercher le premier message 'system' pour y concaténer la mémoire
        $systemFound = false;
        foreach ($messages as $i => $entry) {
            if (is_array($entry) && isset($entry['role']) && 'system' === $entry['role']) {
                /** @var array{role: string, content?: mixed} $entry */
                $oldContent = is_string($entry['content'] ?? null) ? (string) $entry['content'] : '';
                $messages[$i] = [
                    'role' => 'system',
                    'content' => $oldContent.$memoryString,
                ];
                $systemFound = true;
                break;
            }
        }

        // Cas de fallback (anormal mais géré) où aucun message système n'existerait
        if (!$systemFound) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => ltrim($memoryString),
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

        return null !== $id ? (string) $id : null;
    }
}
