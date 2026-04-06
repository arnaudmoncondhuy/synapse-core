<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

use ArnaudMoncondhuy\SynapseCore\Event\SynapseChunkReceivedEvent;
use ArnaudMoncondhuy\SynapseCore\Event\SynapseTokenStreamedEvent;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\ChunkProcessorResult;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\TokenUsage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Traite l'itérable de chunks reçus du LLM et accumule les données du tour courant.
 *
 * Dispatche :
 *   - SynapseChunkReceivedEvent (chaque chunk brut, pour debug)
 *   - SynapseTokenStreamedEvent (chaque fragment de texte, pour streaming front)
 */
class ChunkProcessor
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * Itère sur les chunks produits par le client LLM et retourne un résultat consolidé.
     *
     * @param iterable<mixed> $chunks
     */
    public function process(iterable $chunks, int $turn): ChunkProcessorResult
    {
        $modelText = '';
        $modelToolCalls = [];
        $usage = TokenUsage::empty();
        $safetyRatings = [];
        $providerRawParts = [];
        $generatedAttachments = [];

        foreach ($chunks as $chunkMixed) {
            if (!is_array($chunkMixed)) {
                continue;
            }
            /** @var array<string, mixed> $chunk */
            $chunk = $chunkMixed;

            // Dispatch ChunkReceivedEvent (for debug logging and streaming)
            $this->dispatcher->dispatch(new SynapseChunkReceivedEvent($chunk, $turn));

            // Accumulate text
            if (!empty($chunk['text']) && is_string($chunk['text'])) {
                $chunkText = (string) $chunk['text'];
                $modelText .= $chunkText;
                $this->dispatcher->dispatch(new SynapseTokenStreamedEvent($chunkText, $turn));
            }

            // Usage: keep the LAST chunk's usage (not accumulated).
            // Gemini sends cumulative usageMetadata in every chunk; OpenAI sends
            // usage only in the final chunk. In both cases the last value is the total.
            if (!empty($chunk['usage']) && is_array($chunk['usage'])) {
                $usage = TokenUsage::fromArray($chunk['usage']);
            }

            if (!empty($chunk['safety_ratings']) && is_array($chunk['safety_ratings'])) {
                $safetyRatings = $chunk['safety_ratings'];
            }

            // Accumulate raw provider parts (thinking + functionCall) for multi-turn history
            if (!empty($chunk['_provider_raw_parts']) && is_array($chunk['_provider_raw_parts'])) {
                foreach ($chunk['_provider_raw_parts'] as $rawPart) {
                    $providerRawParts[] = $rawPart;
                }
            }

            // Accumulate generated attachments (inline image/file generation)
            if (!empty($chunk['attachments']) && is_array($chunk['attachments'])) {
                foreach ($chunk['attachments'] as $att) {
                    if (is_array($att) && isset($att['mime_type'], $att['data'])) {
                        $generatedAttachments[] = $att;
                    }
                }
            }

            // Handle blocked responses — si bloqué, on n'exécute pas les function_calls du même chunk
            if ((bool) ($chunk['blocked'] ?? false)) {
                $reason = is_string($chunk['blocked_reason'] ?? null) ? (string) $chunk['blocked_reason'] : 'contenu bloqué par les filtres de sécurité';
                $blockedMsg = "⚠️ Ma réponse a été bloquée ({$reason}). Veuillez reformuler votre demande.";
                $modelText .= $blockedMsg;
                $this->dispatcher->dispatch(new SynapseTokenStreamedEvent($blockedMsg, $turn));
                continue; // Skip function_calls collection for this chunk
            }

            // Collect function calls in OpenAI format
            if (!empty($chunk['function_calls']) && is_array($chunk['function_calls'])) {
                foreach ($chunk['function_calls'] as $fc) {
                    if (!is_array($fc)) {
                        continue;
                    }
                    $nameMixed = $fc['name'] ?? null;
                    if (null === $nameMixed && isset($fc['function']) && is_array($fc['function'])) {
                        $nameMixed = $fc['function']['name'] ?? null;
                    }
                    $name = is_string($nameMixed) ? $nameMixed : '';
                    if ('' === $name) {
                        continue;
                    }

                    $rawArgs = $fc['args'] ?? [];
                    if (empty($rawArgs) && isset($fc['function']) && is_array($fc['function'])) {
                        $rawArgs = $fc['function']['arguments'] ?? [];
                    }
                    $argsJson = is_string($rawArgs) ? $rawArgs : json_encode($rawArgs, JSON_UNESCAPED_UNICODE);
                    $toolCall = [
                        'id' => is_string($fc['id'] ?? null) ? (string) $fc['id'] : 'call_'.bin2hex(random_bytes(6)),
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => $argsJson,
                        ],
                    ];
                    $modelToolCalls[] = $toolCall;
                }
            }
        }

        return new ChunkProcessorResult($modelText, $modelToolCalls, $usage, $safetyRatings, $providerRawParts, $generatedAttachments);
    }
}
