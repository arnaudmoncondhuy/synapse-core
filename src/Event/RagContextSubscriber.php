<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Rag\RagManager;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseAgentRepository;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseRagSourceRepository;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Injecte le contexte documentaire (RAG) dans le prompt avant l'envoi au LLM.
 *
 * Écoute SynapsePrePromptEvent avec priorité 40 :
 * - Après ContextBuilderSubscriber (100) et MemoryContextSubscriber (50)
 * - Interroge les sources RAG assignées à l'agent courant
 * - Injecte les résultats dans le system prompt
 */
class RagContextSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RagManager $ragManager,
        private SynapseAgentRepository $agentRepository,
        private SynapseRagSourceRepository $ragSourceRepository,
        private ?SynapseProfiler $profiler = null,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SynapsePrePromptEvent::class => ['onPrePrompt', 40],
        ];
    }

    public function onPrePrompt(SynapsePrePromptEvent $event): void
    {
        $options = $event->getOptions();
        $agentKey = $options['agent'] ?? null;

        if (!is_string($agentKey) || '' === $agentKey) {
            return;
        }

        $agent = $this->agentRepository->findByKey($agentKey);
        if (!$agent) {
            return;
        }

        $sourceSlugs = $agent->getAllowedRagSources();
        if (empty($sourceSlugs)) {
            return;
        }

        $message = $event->getMessage();
        if (empty($message)) {
            return;
        }

        $maxResults = $agent->getRagMaxResults();
        $minScore = $agent->getRagMinScore();

        $error = null;
        $results = [];

        try {
            if ($this->profiler) {
                $this->profiler->start('RAG', 'RAG Context Search', 'Recherche sémantique dans les sources RAG assignées à l\'agent.');
            }

            $results = $this->ragManager->search($message, $sourceSlugs, $maxResults, $minScore);

            if ($this->profiler) {
                $this->profiler->stop('RAG', 'RAG Context Search', 0);
            }
        } catch (\Throwable $e) {
            if ($this->profiler) {
                $this->profiler->stop('RAG', 'RAG Context Search', 0);
            }
            $error = $e->getMessage();
        }

        $prompt = $event->getPrompt();
        $metadata = is_array($prompt['metadata'] ?? null) ? $prompt['metadata'] : [];

        // Toujours enregistrer les metadata pour le debug panel
        $metadata['rag_matching'] = [
            'agent' => $agentKey,
            'sources_queried' => $sourceSlugs,
            'found' => count($results),
            'threshold' => $minScore,
            'details' => array_map(fn ($r) => [
                'score' => $r['score'],
                'source' => $r['sourceSlug'],
                'content' => substr($r['content'], 0, 80).'...',
            ], $results),
            'error' => $error,
        ];
        $prompt['metadata'] = $metadata;

        if (empty($results)) {
            $event->setPrompt($prompt);

            return;
        }

        // Grouper les résultats par source
        $grouped = [];
        foreach ($results as $r) {
            $grouped[$r['sourceSlug']][] = $r;
        }

        // Construire le bloc RAG
        $ragBlock = "\n\n---\n\n";

        if ($this->translator) {
            $ragBlock .= $this->translator->trans('synapse.core.prompt.rag_block_header', [], 'synapse_core')."\n\n";
        } else {
            $ragBlock .= "### CONTEXTE DOCUMENTAIRE (Base de connaissance)\n";
            $ragBlock .= "Les informations suivantes proviennent de la base de connaissance de l'organisation et sont pertinentes pour la question de l'utilisateur :\n\n";
        }

        foreach ($grouped as $sourceSlug => $sourceResults) {
            // Résoudre le nom de la source
            $source = $this->ragSourceRepository->findBySlug($sourceSlug);
            $sourceName = $source ? $source->getName() : $sourceSlug;

            $ragBlock .= "**[Source : {$sourceName}]**\n";
            foreach ($sourceResults as $r) {
                $ragBlock .= '- '.$r['content']."\n";
            }
            $ragBlock .= "\n";
        }

        if ($this->translator) {
            $ragBlock .= $this->translator->trans('synapse.core.prompt.rag_block_instruction', [], 'synapse_core');
        } else {
            $ragBlock .= "Instruction : Utilise ces informations pour répondre à la question de l'utilisateur. Cite la source si cela est pertinent.";
        }

        // Injecter dans le system prompt
        $contentsRaw = $prompt['contents'] ?? [];
        $messages = is_array($contentsRaw) ? $contentsRaw : [];

        $systemFound = false;
        foreach ($messages as $i => $entry) {
            if (is_array($entry) && isset($entry['role']) && 'system' === $entry['role']) {
                $oldContent = is_string($entry['content'] ?? null) ? (string) $entry['content'] : '';
                $messages[$i] = [
                    'role' => 'system',
                    'content' => $oldContent.$ragBlock,
                ];
                $systemFound = true;
                break;
            }
        }

        if (!$systemFound) {
            array_unshift($messages, [
                'role' => 'system',
                'content' => ltrim($ragBlock),
            ]);
        }

        $prompt['contents'] = $messages;
        $event->setPrompt($prompt);
    }
}
