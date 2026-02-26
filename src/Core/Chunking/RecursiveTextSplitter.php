<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Chunking;

use ArnaudMoncondhuy\SynapseCore\Contract\TextSplitterInterface;

/**
 * Découpeur de texte récursif.
 * 
 * Tente de découper le texte sur des séparateurs naturels (paragraphes, lignes, espaces)
 * pour conserver le maximum de contexte sémantique dans chaque segment.
 */
class RecursiveTextSplitter implements TextSplitterInterface
{
    /** @var string[] Liste des séparateurs par ordre de priorité décroissante */
    private array $separators = ["\n\n", "\n", " ", ""];

    public function splitText(string $text, int $chunkSize = 1000, int $chunkOverlap = 200): array
    {
        return $this->recursiveSplit($text, $this->separators, $chunkSize, $chunkOverlap);
    }

    /**
     * @param string $text
     * @param string[] $separators
     */
    private function recursiveSplit(string $text, array $separators, int $chunkSize, int $chunkOverlap): array
    {
        $finalChunks = [];

        // Trouver le meilleur séparateur actuel
        $separator = "";
        $newSeparators = [];

        foreach ($separators as $i => $s) {
            if ($s === "" || str_contains($text, $s)) {
                $separator = $s;
                $newSeparators = array_slice($separators, $i + 1);
                break;
            }
        }

        // Découper le texte avec ce séparateur
        $splits = ($separator === "") ? str_split($text) : explode($separator, $text);

        $goodSplits = [];
        foreach ($splits as $s) {
            if ($s === "") continue;
            $goodSplits[] = $s;
        }

        $currentDoc = [];
        $totalLen = 0;

        foreach ($goodSplits as $s) {
            $sLen = mb_strlen($s);

            // Si le segment actuel + le nouveau dépasse la taille max
            if ($totalLen + $sLen + ($separator !== "" ? mb_strlen($separator) : 0) > $chunkSize) {
                if ($totalLen > 0) {
                    $doc = $this->joinDocs($currentDoc, $separator);
                    if ($doc !== null) {
                        $finalChunks[] = $doc;
                    }

                    // Gérer le chevauchement (overlap)
                    while ($totalLen > $chunkOverlap || ($totalLen + $sLen > $chunkSize && $totalLen > 0)) {
                        $removed = array_shift($currentDoc);
                        $totalLen -= mb_strlen($removed) + ($separator !== "" ? mb_strlen($separator) : 0);
                    }
                }

                // Si le segment individuel est toujours trop gros, on descend d'un niveau de séparateur
                if ($sLen > $chunkSize) {
                    $subChunks = $this->recursiveSplit($s, $newSeparators, $chunkSize, $chunkOverlap);
                    foreach ($subChunks as $sc) {
                        $finalChunks[] = $sc;
                    }
                } else {
                    $currentDoc[] = $s;
                    $totalLen += $sLen + ($separator !== "" ? mb_strlen($separator) : 0);
                }
            } else {
                $currentDoc[] = $s;
                $totalLen += $sLen + ($separator !== "" ? mb_strlen($separator) : 0);
            }
        }

        // Ajouter le dernier reliquat
        $doc = $this->joinDocs($currentDoc, $separator);
        if ($doc !== null) {
            $finalChunks[] = $doc;
        }

        return $finalChunks;
    }

    /**
     * @param string[] $docs
     */
    private function joinDocs(array $docs, string $separator): ?string
    {
        $text = implode($separator, $docs);
        $text = trim($text);
        return $text === "" ? null : $text;
    }
}
