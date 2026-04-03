<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Engine;

/**
 * Service gérant la "Fenêtre Glissante" pour éviter de dépasser la limite de contexte des LLM.
 */
class ContextTruncationService
{
    private const TOKENS_MARGIN = 1000;

    /**
     * Tronque un historique de messages pour qu'il tienne dans le budget de tokens spécifié.
     *
     * @param array<int, array<string, mixed>> $messages Tableau de messages (Ex: [['role' => '...', 'content' => '...'], ...])
     * @param int $maxTokens Capacité maximale de la fenêtre de contexte
     *
     * @return array<int, array<string, mixed>> L'historique tronqué, remis dans le bon ordre chronologique
     */
    public function truncate(array $messages, int $maxTokens): array
    {
        if (empty($messages)) {
            return [];
        }

        // 1. Extraire le message système s'il est au début
        $systemMessage = null;
        if (isset($messages[0]['role']) && 'system' === $messages[0]['role']) {
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

        if (null !== $systemMessage) {
            $finalMessages[] = $systemMessage;
        }

        if (!empty($keptMessages)) {
            // Remettre dans l'ordre chronologique (du plus ancien au plus récent)
            $keptMessages = array_reverse($keptMessages);
            foreach ($keptMessages as $msg) {
                $finalMessages[] = $msg;
            }
        }

        if (null !== $lastMessage) {
            $finalMessages[] = $lastMessage;
        }

        return $finalMessages;
    }

    /**
     * Estime le nombre de tokens pour un tableau de messages (contenu + historique).
     * Heuristique : 1 token ≈ 4 caractères.
     *
     * @param array<int, array<string, mixed>> $contents
     */
    public function estimateTokensForContents(array $contents): int
    {
        $total = 0;
        foreach ($contents as $message) {
            $total += $this->estimateTokens($message);
        }

        return $total;
    }

    /**
     * Estime le nombre de tokens d'un message OpenAI (texte ou multimodal).
     *
     * - Texte        : heuristique 1 token ≈ 4 caractères
     * - Image        : max(85, min(1700, octets_fichier / 100)) — calibré sur les tuiles des principaux LLM
     * - Audio        : max(100, octets_fichier / 12_000) — ~6 tokens/sec à 128kbps
     * - Vidéo        : max(500, octets_fichier / 1_000) — surestimation intentionnelle
     * - PDF/document : octets_fichier / 400 — texte dense compressé
     *
     * Le contenu peut être une string (texte simple) ou un tableau de parts (format OpenAI multimodal) :
     *   [['type' => 'text', 'text' => '...'], ['type' => 'image_url', 'image_url' => ['url' => 'data:...']], ...]
     *
     * @param array<string, mixed> $message
     */
    private function estimateTokens(array $message): int
    {
        $content = $message['content'] ?? '';
        $total = 0;

        if (is_string($content)) {
            // Cas simple : contenu texte uniquement
            $total += (int) ceil(mb_strlen($content) / 4);
        } elseif (is_array($content)) {
            // Cas multimodal : tableau de parts OpenAI
            foreach ($content as $part) {
                if (!is_array($part)) {
                    continue;
                }
                $type = $part['type'] ?? 'text';

                if ('text' === $type) {
                    $text = is_string($part['text'] ?? null) ? $part['text'] : '';
                    $total += (int) ceil(mb_strlen($text) / 4);
                } elseif ('image_url' === $type) {
                    $total += $this->estimateMediaTokens($part['image_url']['url'] ?? '', 'image');
                } elseif ('audio_url' === $type || 'input_audio' === $type) {
                    $url = $part['audio_url']['url'] ?? ($part['input_audio']['data'] ?? '');
                    $total += $this->estimateMediaTokens((string) $url, 'audio');
                } elseif ('video_url' === $type) {
                    $total += $this->estimateMediaTokens($part['video_url']['url'] ?? '', 'video');
                } elseif ('document' === $type || 'file' === $type) {
                    $url = $part['source']['data'] ?? ($part['file']['url'] ?? '');
                    $mimeType = is_string($part['source']['media_type'] ?? null) ? (string) $part['source']['media_type'] : '';
                    $category = str_starts_with($mimeType, 'image/') ? 'image'
                        : (str_starts_with($mimeType, 'audio/') ? 'audio'
                        : (str_starts_with($mimeType, 'video/') ? 'video' : 'document'));
                    $total += $this->estimateMediaTokens((string) $url, $category);
                } else {
                    // Type inconnu : sérialise en JSON comme fallback
                    $total += (int) ceil(mb_strlen((string) json_encode($part)) / 4);
                }
            }
        } elseif (is_scalar($content)) {
            $total += (int) ceil(mb_strlen((string) $content) / 4);
        }

        // Tool calls (function calling)
        if (isset($message['tool_calls'])) {
            $total += (int) ceil(mb_strlen((string) json_encode($message['tool_calls'], JSON_UNESCAPED_UNICODE)) / 4);
        }

        return max(1, $total);
    }

    /**
     * Estime les tokens d'un média à partir de son URL (data URI base64 ou URL externe).
     *
     * Pour les data URIs  : extrait le payload base64 et calcule la taille réelle du fichier.
     * Pour les URLs HTTP  : utilise un forfait par catégorie (taille inconnue).
     */
    private function estimateMediaTokens(string $url, string $category): int
    {
        $bytes = 0;

        if (str_starts_with($url, 'data:')) {
            // data:<mime>;base64,<payload>
            $commaPos = strpos($url, ',');
            if (false !== $commaPos) {
                // base64 length * 0.75 = octets réels du fichier
                $base64Payload = substr($url, $commaPos + 1);
                $bytes = (int) (strlen($base64Payload) * 0.75);

                // Affiner la catégorie depuis le MIME embarqué si nécessaire
                if ('image' === $category || 'audio' === $category || 'video' === $category || 'document' === $category) {
                    $header = substr($url, 5, $commaPos - 5); // ex: "image/jpeg;base64"
                    $mimeType = explode(';', $header)[0];
                    $category = str_starts_with($mimeType, 'image/') ? 'image'
                        : (str_starts_with($mimeType, 'audio/') ? 'audio'
                        : (str_starts_with($mimeType, 'video/') ? 'video'
                        : (in_array($mimeType, ['application/pdf', 'text/plain', 'application/msword'], true) ? 'document'
                        : $category)));
                }
            }
        }
        // Pour les URLs externes, $bytes reste 0 → forfait par catégorie ci-dessous

        return match ($category) {
            'image' => 0 === $bytes ? 512 : max(85, min(1700, (int) ($bytes / 100))),
            'audio' => 0 === $bytes ? 360 : max(100, (int) ($bytes / 12_000)),
            'video' => 0 === $bytes ? 2000 : max(500, (int) ($bytes / 1_000)),
            'document' => 0 === $bytes ? 1000 : max(50, (int) ($bytes / 400)),
            default => 0 === $bytes ? 512 : max(85, (int) ($bytes / 100)),
        };
    }
}
