<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptBuildEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptCaptureEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptEnrichEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptFinalizeEvent;
use ArnaudMoncondhuy\SynapseCore\Event\Prompt\PromptOptimizeEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\SynapseRuntimeConfig;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Exécute les 5 phases du pipeline de construction du prompt de manière séquentielle et explicite.
 *
 * Ordre garanti par le code, non par des magic numbers :
 *   BUILD → ENRICH → OPTIMIZE → FINALIZE → CAPTURE
 *
 * Chaque phase reçoit le résultat (prompt + config) de la phase précédente.
 */
class PromptPipeline
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Construit le prompt final en passant par les 5 phases.
     *
     * @param array<string, mixed> $options
     * @param list<array{mime_type: string, data: string}> $images
     *
     * @return array{prompt: array<string, mixed>, config: ?SynapseRuntimeConfig}
     */
    public function build(string $message, array $options, array $images = []): array
    {
        // Phase 1 : BUILD — ContextBuilderSubscriber construit le prompt de base
        $buildEvent = $this->dispatcher->dispatch(new PromptBuildEvent($message, $options, [], null, $images));
        $prompt = $buildEvent->getPrompt();
        $config = $buildEvent->getConfig();

        // Phase 2 : ENRICH — MemoryContextSubscriber (prio 50) + RagContextSubscriber (prio 40)
        $enrichEvent = $this->dispatcher->dispatch(new PromptEnrichEvent($message, $options, $prompt, $config, $images));
        $prompt = $enrichEvent->getPrompt();
        $config = $enrichEvent->getConfig() ?? $config;

        // Phase 3 : OPTIMIZE — ContextTruncationSubscriber
        $optimizeEvent = $this->dispatcher->dispatch(new PromptOptimizeEvent($message, $options, $prompt, $config, $images));
        $prompt = $optimizeEvent->getPrompt();
        $config = $optimizeEvent->getConfig() ?? $config;

        // Phase 4 : FINALIZE — MasterPromptSubscriber
        $finalizeEvent = $this->dispatcher->dispatch(new PromptFinalizeEvent($message, $options, $prompt, $config, $images));
        $prompt = $finalizeEvent->getPrompt();
        $config = $finalizeEvent->getConfig() ?? $config;

        // Phase 5 : CAPTURE — DebugLogSubscriber (lecture seule, ne modifie pas le prompt)
        $this->dispatcher->dispatch(new PromptCaptureEvent($message, $options, $prompt, $config, $images));

        return ['prompt' => $prompt, 'config' => $config];
    }
}
