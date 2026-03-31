<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseStatusChangedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\MultiTurnResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Orchestre la boucle multi-tours : appelle le LLM, traite les chunks, exécute les outils si nécessaire.
 *
 * Fix Bug 2 : les données raw de TOUS les tours sont accumulées (pas seulement le tour 0).
 */
class MultiTurnExecutor
{
    public function __construct(
        private readonly ChunkProcessor $chunkProcessor,
        private readonly ToolExecutor $toolExecutor,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly SynapseProfiler $profiler,
    ) {
    }

    /**
     * Exécute la boucle multi-tours et retourne le résultat consolidé.
     *
     * @param array<string, mixed> $prompt Modifié par référence (historique ajouté à chaque tour)
     */
    public function execute(
        array &$prompt,
        LlmClientInterface $activeClient,
        bool $streamingEnabled,
        int $maxTurns,
    ): MultiTurnResult {
        $fullTextAccumulator = '';
        $cumulativeUsage = TokenUsage::empty();
        $finalSafetyRatings = [];
        $allTurnsRawData = [];

        for ($turn = 0; $turn < $maxTurns; ++$turn) {
            if ($turn > 0) {
                $this->dispatcher->dispatch(new SynapseStatusChangedEvent('Réflexion supplémentaire...', 'thinking', $turn));
            }

            $debugOut = [];

            // ── LLM CALL (Streaming or Sync) ──
            $this->profiler->start('LLM', 'LLM Network Call & Streaming', "Durée totale de l'échange réseau avec l'API du fournisseur (attente + réception des chunks).");
            /** @var array<int, array<string, mixed>> $contents */
            $contents = $prompt['contents'];
            /** @var array<int, array<string, mixed>> $toolDefinitions */
            $toolDefinitions = $prompt['toolDefinitions'] ?? [];

            if ($streamingEnabled) {
                $chunks = $activeClient->streamGenerateContent($contents, $toolDefinitions, null, $debugOut);
            } else {
                $response = $activeClient->generateContent($contents, $toolDefinitions, null, [], $debugOut);
                $chunks = [$response];
            }

            // ── PROCESS CHUNKS ──
            $chunkResult = $this->chunkProcessor->process($chunks, $turn);

            $modelText = $chunkResult->modelText;
            $modelToolCalls = $chunkResult->modelToolCalls;

            // ── RESOLVE TIMING (after generator fully consumed) ──
            $this->profiler->stop('LLM', 'LLM Network Call & Streaming', $turn);

            // ── ACCUMULATE RAW DATA (Bug 2 fix: tous les tours) ──
            if (!empty($debugOut)) {
                if (0 === $turn && !empty($debugOut['raw_request_body'])) {
                    $allTurnsRawData['raw_request_body'] = $debugOut['raw_request_body'];
                }
                if (!empty($debugOut['raw_api_chunks']) && is_array($debugOut['raw_api_chunks'])) {
                    $allTurnsRawData['raw_api_chunks'] = array_merge(
                        $allTurnsRawData['raw_api_chunks'] ?? [],
                        $debugOut['raw_api_chunks']
                    );
                }
                if (!empty($debugOut['raw_api_response'])) {
                    $allTurnsRawData['raw_api_response'] = $debugOut['raw_api_response'];
                }
            }

            // ── ACCUMULATE USAGE AND SAFETY ──
            $cumulativeUsage = $cumulativeUsage->add($chunkResult->usage);
            if (!empty($chunkResult->safetyRatings)) {
                $finalSafetyRatings = $chunkResult->safetyRatings;
            }

            $fullTextAccumulator .= $modelText;

            // ── ADD MODEL RESPONSE TO HISTORY (OpenAI format) ──
            if ('' !== $modelText || !empty($modelToolCalls)) {
                $entry = ['role' => 'assistant', 'content' => $modelText ?: null];
                if (!empty($modelToolCalls)) {
                    $entry['tool_calls'] = $modelToolCalls;
                }
                // Store raw Gemini parts so toGeminiMessages can re-inject thoughtSignature verbatim
                if (!empty($chunkResult->geminiRawParts)) {
                    $entry['_gemini_raw_parts'] = $chunkResult->geminiRawParts;
                }
                $prompt['contents'][] = $entry;
            }

            // ── PROCESS TOOL CALLS ──
            if (!empty($modelToolCalls)) {
                $this->toolExecutor->execute($prompt, $modelToolCalls, $turn);
                // Continuer la boucle : le LLM reçoit les résultats et peut enchaîner
                continue;
            }

            // Aucun tool call → fin de l'échange
            break;
        }

        return new MultiTurnResult($fullTextAccumulator, $cumulativeUsage, $finalSafetyRatings, $allTurnsRawData);
    }
}
