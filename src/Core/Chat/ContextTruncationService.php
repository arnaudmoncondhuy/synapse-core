<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chat;

/**
 * Service gérant la "Fenêtre Glissante" pour éviter de dépasser la limite de contexte des LLM.
 */
class ContextTruncationService
{
    private const TOKENS_MARGIN = 1000;

    /**
     * Tronque un historique de messages pour qu'il tienne dans le budget de tokens spécifié.
     *
     * @param array<int, array> $messages  Tableau de messages (Ex: [['role' => '...', 'content' => '...'], ...])
     * @param int               $maxTokens Capacité maximale de la fenêtre de contexte
     *
     * @return array<int, array> L'historique tronqué, remis dans le bon ordre chronologique.
     */
    public function truncate(array $messages, int $maxTokens): array
    {
        if (empty($messages)) {
            return [];
        }

        // 1. Extraire le message système s'il est au début
        $systemMessage = null;
        if (isset($messages[0]['role']) && $messages[0]['role'] === 'system') {
            $systemMessage = array_shift($messages);
        }

        // 2. Extraire le tout dernier message (qui correspond à la question actuelle de l'utilisateur)
        $lastMessage = null;
        if (!empty($messages)) {
            $lastMessage = array_pop($messages);
        }

        $systemTokens = $systemMessage ? $this->estimateTokens($systemMessage) : 0;
        $lastMessageTokens = $lastMessage ? $this->estimateTokens($lastMessage) : 0;

        // 3. Calculer le budget restant
        $budget = $maxTokens - ($systemTokens + $lastMessageTokens) - self::TOKENS_MARGIN;

        $keptMessages = [];

        // 4. Parcourir les messages restants à l'envers (du plus récent au plus ancien)
        if ($budget > 0) {
            $messages = array_reverse($messages);
            foreach ($messages as $message) {
                $msgTokens = $this->estimateTokens($message);

                if ($budget - $msgTokens >= 0) {
                    $keptMessages[] = $message;
                    $budget -= $msgTokens;
                } else {
                    // Si on dépasse le budget, on arrête de remonter dans le temps
                    break;
                }
            }
        }

        // 5. Reconstruire le tableau final chronologiquement
        $finalMessages = [];

        if ($systemMessage !== null) {
            $finalMessages[] = $systemMessage;
        }

        if (!empty($keptMessages)) {
            // Remettre dans l'ordre chronologique (du plus ancien au plus récent)
            $keptMessages = array_reverse($keptMessages);
            foreach ($keptMessages as $msg) {
                $finalMessages[] = $msg;
            }
        }

        if ($lastMessage !== null) {
            $finalMessages[] = $lastMessage;
        }

        return $finalMessages;
    }

    /**
     * Heuristique : 1 token ≈ 4 caractères.
     */
    private function estimateTokens(array $message): int
    {
        $text = $message['content'] ?? '';

        if (isset($message['tool_calls'])) {
            $text .= json_encode($message['tool_calls'], JSON_UNESCAPED_UNICODE);
        }

        return (int) ceil(mb_strlen((string) $text) / 4);
    }
}
