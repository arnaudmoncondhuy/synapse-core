<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Util\PromptUtil;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Injecte silencieusement les souvenirs de l'utilisateur dans le contexte avant l'envoi au LLM.
 *
 * Phase ENRICH (priorité 50) — s'exécute après ContextBuilderSubscriber (phase BUILD)
 * et avant RagContextSubscriber (priorité 40 dans la même phase ENRICH).
 */
class MemoryContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MemoryManager $memoryManager,
        private ?TokenStorageInterface $tokenStorage = null,
        private int $maxMemories = 5,
        private ?SynapseProfiler $profiler = null,
        private ?TranslatorInterface $translator = null,
        private ?LoggerInterface $logger = null,
        private ?EventDispatcherInterface $dispatcher = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PromptEnrichEvent::class => ['onPrePrompt', 50], // Memory avant RAG (50 > 40)
        ];
    }

    public function onPrePrompt(PromptEnrichEvent $event): void
    {
        $options = $event->getOptions();

        // Skip memory enrichment for stateless/internal calls (e.g. title_generation)
        // where injecting user memories is wasteful and unnecessary.
        if (!empty($options['stateless'])) {
            return;
        }

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

                $statusMessage = $this->translator
                    ? $this->translator->trans('synapse.core.memory.searching', [], 'synapse_core')
                    : 'Recherche dans votre mémoire...';
                $this->dispatcher?->dispatch(new SynapseStatusChangedEvent($statusMessage, 'memory:search'));

                $memories = $this->memoryManager->recall($message, $userId, $conversationId, $this->maxMemories);

                if ($this->profiler) {
                    $this->profiler->stop('Memory', 'PgVector Memory Search', 0);
                }

                $doneMessage = $this->translator
                    ? $this->translator->trans('synapse.core.memory.searching_done', [], 'synapse_core')
                    : 'Analyse de la demande...';
                $this->dispatcher?->dispatch(new SynapseStatusChangedEvent($doneMessage, 'thinking'));
            } catch (\Throwable $e) {
                if ($this->profiler) {
                    $this->profiler->stop('Memory', 'PgVector Memory Search', 0);
                }
                $error = $e->getMessage();
                $this->logger?->error('Synapse Memory: erreur lors du rappel mémoire.', [
                    'exception' => $e,
                ]);
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

        // Dispatch transparency event for sidebar
        if (!empty($relevant) && null !== $this->dispatcher) {
            $memoryTransparencyResults = array_map(fn ($m) => [
                'score' => $m['score'],
                'content_preview' => mb_substr($m['content'], 0, 80),
            ], array_values($relevant));
            $this->dispatcher->dispatch(new SynapseMemoryResultsEvent(
                $memoryTransparencyResults,
                \count($relevant),
            ));
        }

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
                'memories' => $memoryBlock,
            ], 'synapse_core');
        } else {
            $memoryString .= "### 🧠 MÉMOIRE ET CONTEXTE UTILISATEUR\n";
            $memoryString .= "Les informations suivantes ont été mémorisées lors de conversations précédentes avec l'utilisateur :\n{$memoryBlock}\n";
            $memoryString .= "Instruction: Utilise ces informations de manière naturelle si elles sont pertinentes pour répondre, mais ne dis jamais explicitement 'd'après mes souvenirs' ou 'je me souviens que'. Agis simplement en tenant compte de ce contexte.";
        }

        $contentsRaw = $prompt['contents'] ?? [];
        $messages = is_array($contentsRaw) ? $contentsRaw : [];

        $prompt['contents'] = PromptUtil::appendToSystemMessage($messages, $memoryString);
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
