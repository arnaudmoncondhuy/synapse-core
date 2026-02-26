<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\VectorStore;

use ArnaudMoncondhuy\SynapseCore\Contract\VectorStoreInterface;

/**
 * Implémentation en mémoire pour les tests unitaires ou le développement léger.
 * Les données sont perdues à la fin de la requête.
 */
class InMemoryVectorStore implements VectorStoreInterface
{
    /** @var array<int, array{vector: array<int, float>, payload: array<string, mixed>}> */
    private array $storage = [];

    public function saveMemory(array $vector, array $payload): void
    {
        $this->storage[] = [
            'vector'  => $vector,
            'payload' => $payload,
        ];
    }

    public function searchSimilar(array $vector, int $limit = 5, array $filters = []): array
    {
        if (empty($this->storage)) {
            return [];
        }

        $results = [];
        foreach ($this->storage as $item) {
            $score = $this->calculateCosineSimilarity($vector, $item['vector']);
            $results[] = [
                'payload' => $item['payload'],
                'score'   => $score,
            ];
        }

        // Tri par score décroissant
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($results, 0, $limit);
    }

    /**
     * Calcule la similitude cosinus entre deux vecteurs.
     */
    private function calculateCosineSimilarity(array $vec1, array $vec2): float
    {
        $dotProduct = 0.0;
        $norm1 = 0.0;
        $norm2 = 0.0;

        $count = count($vec1);
        for ($i = 0; $i < $count; $i++) {
            $v1 = $vec1[$i] ?? 0.0;
            $v2 = $vec2[$i] ?? 0.0;
            $dotProduct += $v1 * $v2;
            $norm1 += $v1 * $v1;
            $norm2 += $v2 * $v2;
        }

        if ($norm1 === 0.0 || $norm2 === 0.0) {
            return 0.0;
        }

        return $dotProduct / (sqrt($norm1) * sqrt($norm2));
    }
}
