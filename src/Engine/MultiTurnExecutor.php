<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Contract\LlmClientInterface;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseMultiTurnIterationEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseStatusChangedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Exception\StructuredOutputParseException;
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
     * @param array<string, mixed> $llmOptions Options forwardées aux appels LLM (ex : `response_format`,
     *                                         `thinking`). Le `response_format` déclenche en plus le
     *                                         parsing JSON de la réponse finale, exposé via
     *                                         {@see MultiTurnResult::$structuredData}.
     *
     * @throws StructuredOutputParseException si `response_format` est fourni mais la réponse finale
     *                                        n'est pas un JSON valide
     */
    public function execute(
        array &$prompt,
        LlmClientInterface $activeClient,
        bool $streamingEnabled,
        int $maxTurns,
        array $llmOptions = [],
    ): MultiTurnResult {
        $fullTextAccumulator = '';
        $cumulativeUsage = TokenUsage::empty();
        $finalSafetyRatings = [];
        $allTurnsRawData = [];
        $allGeneratedAttachments = [];

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
                $chunks = $activeClient->streamGenerateContent($contents, $toolDefinitions, null, $llmOptions, $debugOut);
            } else {
                $response = $activeClient->generateContent($contents, $toolDefinitions, null, $llmOptions, $debugOut);
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
                if (0 === $turn && !empty($debugOut['actual_request_params'])) {
                    $allTurnsRawData['actual_request_params'] = $debugOut['actual_request_params'];
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
            if (!empty($chunkResult->generatedAttachments)) {
                foreach ($chunkResult->generatedAttachments as $att) {
                    $allGeneratedAttachments[] = $att;
                }
            }

            $fullTextAccumulator .= $modelText;

            // ── ADD MODEL RESPONSE TO HISTORY (OpenAI format) ──
            if ('' !== $modelText || !empty($modelToolCalls)) {
                $entry = ['role' => 'assistant', 'content' => $modelText ?: null];
                if (!empty($modelToolCalls)) {
                    $entry['tool_calls'] = $modelToolCalls;
                }
                // Store raw provider parts for multi-turn history (e.g. thoughtSignature)
                if (!empty($chunkResult->providerRawParts)) {
                    $entry['_provider_raw_parts'] = $chunkResult->providerRawParts;
                }
                $prompt['contents'][] = $entry;
            }

            // ── PROCESS TOOL CALLS ──
            if (!empty($modelToolCalls)) {
                $this->toolExecutor->execute($prompt, $modelToolCalls, $turn);

                // Dispatch iteration event for transparency sidebar
                $toolSummary = array_map(fn (array $tc) => [
                    'name' => (string) $tc['function']['name'],
                    'args_summary' => mb_substr((string) json_encode(json_decode(is_string($tc['function']['arguments'] ?? null) ? $tc['function']['arguments'] : '{}', true)), 0, 100),
                ], $modelToolCalls);
                $this->dispatcher->dispatch(new SynapseMultiTurnIterationEvent(
                    $turn,
                    $maxTurns,
                    $toolSummary,
                    $chunkResult->usage->toArray(),
                    true,
                ));

                // Continuer la boucle : le LLM reçoit les résultats et peut enchaîner
                continue;
            }

            // Aucun tool call → fin de l'échange
            break;
        }

        // ── SAFETY NET : si la boucle a épuisé ses tours sur des tool calls,
        //    faire un dernier appel LLM SANS outils pour forcer une réponse texte.
        //    Sans cela, l'utilisateur voit les outils s'exécuter puis… rien.
        if ($turn >= $maxTurns && !empty($modelToolCalls)) {
            $this->dispatcher->dispatch(new SynapseStatusChangedEvent(
                'Synthèse des résultats…',
                'thinking',
                $turn,
            ));

            $debugOut = [];
            $this->profiler->start('LLM', 'LLM Network Call & Streaming', 'Appel final de synthèse après épuisement des tours.');

            /** @var array<int, array<string, mixed>> $contents */
            $contents = $prompt['contents'];

            if ($streamingEnabled) {
                $chunks = $activeClient->streamGenerateContent($contents, [], null, $llmOptions, $debugOut);
            } else {
                $response = $activeClient->generateContent($contents, [], null, $llmOptions, $debugOut);
                $chunks = [$response];
            }

            $chunkResult = $this->chunkProcessor->process($chunks, $turn);
            $this->profiler->stop('LLM', 'LLM Network Call & Streaming', $turn);

            if (!empty($debugOut['raw_api_chunks']) && is_array($debugOut['raw_api_chunks'])) {
                $allTurnsRawData['raw_api_chunks'] = array_merge(
                    $allTurnsRawData['raw_api_chunks'] ?? [],
                    $debugOut['raw_api_chunks']
                );
            }

            $cumulativeUsage = $cumulativeUsage->add($chunkResult->usage);
            if (!empty($chunkResult->safetyRatings)) {
                $finalSafetyRatings = $chunkResult->safetyRatings;
            }
            if (!empty($chunkResult->generatedAttachments)) {
                foreach ($chunkResult->generatedAttachments as $att) {
                    $allGeneratedAttachments[] = $att;
                }
            }

            $fullTextAccumulator .= $chunkResult->modelText;

            if ('' !== $chunkResult->modelText) {
                $prompt['contents'][] = ['role' => 'assistant', 'content' => $chunkResult->modelText];
            }
        }

        // Parse structured output if requested (response_format activé).
        // Le parsing intervient sur le texte accumulé du dernier tour (donc après résolution
        // éventuelle de toutes les tool calls). Un JSON invalide lève une exception dédiée
        // conservant le raw text pour debug.
        $structuredData = null;
        if (isset($llmOptions['response_format']) && '' !== $fullTextAccumulator) {
            try {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode($fullTextAccumulator, true, 512, \JSON_THROW_ON_ERROR);
                if (!\is_array($decoded)) {
                    throw new StructuredOutputParseException('La réponse du LLM en mode structured output n\'est pas un objet JSON.', $fullTextAccumulator);
                }
                $structuredData = $decoded;
            } catch (\JsonException $e) {
                throw new StructuredOutputParseException(\sprintf('Impossible de décoder la réponse JSON du LLM : %s', $e->getMessage()), $fullTextAccumulator, $e);
            }
        }

        return new MultiTurnResult(
            $fullTextAccumulator,
            $cumulativeUsage,
            $finalSafetyRatings,
            $allTurnsRawData,
            $allGeneratedAttachments,
            $structuredData,
        );
    }
}
