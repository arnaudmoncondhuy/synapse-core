<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseStatusChangedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallCompletedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseToolCallRequestedEvent;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Exécute les tool calls demandés par le LLM et injecte les résultats dans le prompt.
 *
 * Fix Bug 1 : un tool result null génère quand même un message role:tool (contenu vide),
 * ce qui évite que le LLM re-demande indéfiniment le même outil jusqu'à MAX_TURNS.
 */
class ToolExecutor
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SynapseProfiler $profiler,
        private readonly ?ToolRegistry $toolRegistry = null,
    ) {
    }

    /**
     * Exécute les tool calls et injecte les résultats dans prompt['contents'].
     *
     * @param array<string, mixed> $prompt Modifié par référence : les résultats sont ajoutés à contents
     * @param list<array<string, mixed>> $modelToolCalls Tool calls en format OpenAI
     */
    public function execute(array &$prompt, array $modelToolCalls, int $turn): void
    {
        // Normalise modelToolCalls to event format
        $eventToolCalls = array_map(function ($tc) {
            $decodedArgs = json_decode(is_string($tc['function']['arguments']) ? $tc['function']['arguments'] : '', true);

            return [
                'id' => (string) $tc['id'],
                'name' => (string) $tc['function']['name'],
                'args' => is_array($decodedArgs) ? $decodedArgs : [],
            ];
        }, $modelToolCalls);

        $toolEvent = $this->dispatcher->dispatch(new SynapseToolCallRequestedEvent($eventToolCalls));
        $toolResults = $toolEvent->getResults();

        foreach ($modelToolCalls as $tc) {
            $toolName = (string) $tc['function']['name'];
            $this->profiler->start('Tool', 'Tool Execution: '.$toolName, "Exécution locale d'une fonction (outil) demandée par le LLM.");

            $toolResult = $toolResults[$toolName] ?? null;

            $tool = $this->toolRegistry?->get($toolName);
            $statusMessage = (isset($tool->executingMessage) && is_string($tool->executingMessage))
                ? $tool->executingMessage
                : "Exécution de l'outil: {$toolName}...";
            $this->dispatcher->dispatch(new SynapseStatusChangedEvent($statusMessage, 'tool:'.$toolName, $turn));

            // Toujours ajouter le message role:tool même si le résultat est null,
            // pour éviter que le LLM boucle en re-demandant le même outil (Bug 1 fix).
            $prompt['contents'][] = [
                'role' => 'tool',
                'tool_call_id' => (string) $tc['id'],
                'content' => null !== $toolResult
                    ? (is_string($toolResult) ? $toolResult : json_encode($toolResult, JSON_UNESCAPED_UNICODE))
                    : '',
            ];

            if (null !== $toolResult) {
                $this->dispatcher->dispatch(new SynapseToolCallCompletedEvent($toolName, $toolResult, $tc));
            }

            $this->profiler->stop('Tool', 'Tool Execution: '.$toolName, $turn);
        }
    }
}
