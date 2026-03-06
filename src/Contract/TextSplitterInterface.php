<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

/**
 * Interface pour les stratégies de découpage de texte (Chunking).
 *
 * Utilisé pour découper de longs documents en morceaux gérables
 * avant la vectorisation pour le RAG.
 */
interface TextSplitterInterface
{
    /**
     * Découpe un texte en un tableau de segments (chunks).
     *
     * @param string $text         le texte complet à découper
     * @param int    $chunkSize    taille maximale de chaque segment (souvent en caractères ou tokens)
     * @param int    $chunkOverlap nombre de caractères/tokens chevauchant entre deux segments
     *
     * @return string[] liste des segments de texte
     */
    public function splitText(string $text, int $chunkSize = 1000, int $chunkOverlap = 200): array;
}
