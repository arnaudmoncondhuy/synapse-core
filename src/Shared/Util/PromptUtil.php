<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Util;

/**
 * Utilitaires pour la manipulation du prompt (format OpenAI messages array).
 */
final class PromptUtil
{
    /**
     * Concatène un bloc de texte au message système existant, ou en crée un nouveau.
     *
     * @param array<int, array{role: string, content?: string}> $messages
     *
     * @return array<int, array{role: string, content?: string}>
     */
    public static function appendToSystemMessage(array $messages, string $block): array
    {
        foreach ($messages as $i => $entry) {
            if (is_array($entry) && isset($entry['role']) && 'system' === $entry['role']) {
                $oldContent = is_string($entry['content'] ?? null) ? (string) $entry['content'] : '';
                $messages[$i] = [
                    'role' => 'system',
                    'content' => $oldContent.$block,
                ];

                return $messages;
            }
        }

        array_unshift($messages, [
            'role' => 'system',
            'content' => ltrim($block),
        ]);

        return $messages;
    }
}
