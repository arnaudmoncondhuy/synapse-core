<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chunking;

use ArnaudMoncondhuy\SynapseCore\Contract\TextSplitterInterface;

/**
 * Découpeur de texte à taille fixe.
 * 
 * Découpe le texte brutalement tous les X caractères, 
 * sans tenir compte de la ponctuation ou des mots.
 */
class FixedSizeTextSplitter implements TextSplitterInterface
{
    public function splitText(string $text, int $chunkSize = 1000, int $chunkOverlap = 200): array
    {
        if ($chunkSize <= 0) {
            return [$text];
        }

        $chunks = [];
        $textLength = mb_strlen($text);
        $start = 0;

        while ($start < $textLength) {
            $end = min($start + $chunkSize, $textLength);
            $chunks[] = mb_substr($text, $start, $end - $start);

            if ($end === $textLength) {
                break;
            }

            $start = $end - $chunkOverlap;

            // Sécurité pour éviter les boucles infinies si overlap >= chunkSize
            if ($start >= $end) {
                $start = $end;
            }
        }

        return $chunks;
    }
}
