<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\VectorStore;

use ArnaudMoncondhuy\SynapseCore\VectorStore\InMemoryVectorStore;
use PHPUnit\Framework\TestCase;

class InMemoryVectorStoreTest extends TestCase
{
    private InMemoryVectorStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryVectorStore();
    }

    // -------------------------------------------------------------------------
    // Cas de base
    // -------------------------------------------------------------------------

    public function testSearchOnEmptyStoreReturnsEmptyArray(): void
    {
        $results = $this->store->searchSimilar([1.0, 0.0], 5);

        $this->assertSame([], $results);
    }

    public function testSavedMemoryIsFoundOnSearch(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'document A']);

        $results = $this->store->searchSimilar([1.0, 0.0], 5);

        $this->assertCount(1, $results);
        $this->assertSame('document A', $results[0]['payload']['text']);
    }

    public function testResultContainsScoreField(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'doc']);

        $results = $this->store->searchSimilar([1.0, 0.0], 5);

        $this->assertArrayHasKey('score', $results[0]);
        $this->assertIsFloat($results[0]['score']);
    }

    // -------------------------------------------------------------------------
    // Similarité cosinus
    // -------------------------------------------------------------------------

    public function testIdenticalVectorHasScoreOne(): void
    {
        $vector = [0.6, 0.8]; // vecteur unitaire
        $this->store->saveMemory($vector, ['text' => 'identique']);

        $results = $this->store->searchSimilar($vector, 1);

        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
    }

    public function testOppositeVectorHasScoreMinusOne(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'A']);

        $results = $this->store->searchSimilar([-1.0, 0.0], 1);

        $this->assertEqualsWithDelta(-1.0, $results[0]['score'], 0.0001);
    }

    public function testOrthogonalVectorHasScoreZero(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'A']);

        $results = $this->store->searchSimilar([0.0, 1.0], 1);

        $this->assertEqualsWithDelta(0.0, $results[0]['score'], 0.0001);
    }

    public function testZeroVectorReturnsScoreZero(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'A']);

        $results = $this->store->searchSimilar([0.0, 0.0], 1);

        $this->assertEqualsWithDelta(0.0, $results[0]['score'], 0.0001);
    }

    // -------------------------------------------------------------------------
    // Tri et limite
    // -------------------------------------------------------------------------

    public function testResultsAreSortedByScoreDescending(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'proche']);   // score ~1.0
        $this->store->saveMemory([0.0, 1.0], ['text' => 'orthogonal']); // score ~0.0
        $this->store->saveMemory([0.7, 0.7], ['text' => 'moyen']);    // score ~0.7

        $results = $this->store->searchSimilar([1.0, 0.0], 3);

        $this->assertSame('proche', $results[0]['payload']['text']);
        $this->assertSame('moyen', $results[1]['payload']['text']);
        $this->assertSame('orthogonal', $results[2]['payload']['text']);
    }

    public function testLimitIsRespected(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->store->saveMemory([1.0, 0.0], ['text' => "doc $i"]);
        }

        $results = $this->store->searchSimilar([1.0, 0.0], 3);

        $this->assertCount(3, $results);
    }

    public function testLimitHigherThanStorageReturnsAll(): void
    {
        $this->store->saveMemory([1.0, 0.0], ['text' => 'A']);
        $this->store->saveMemory([0.0, 1.0], ['text' => 'B']);

        $results = $this->store->searchSimilar([1.0, 0.0], 100);

        $this->assertCount(2, $results);
    }

    // -------------------------------------------------------------------------
    // Payload préservé
    // -------------------------------------------------------------------------

    public function testPayloadIsFullyPreserved(): void
    {
        $payload = ['text' => 'contenu', 'source' => 'doc.pdf', 'page' => 3];
        $this->store->saveMemory([1.0, 0.0], $payload);

        $results = $this->store->searchSimilar([1.0, 0.0], 1);

        $this->assertSame($payload, $results[0]['payload']);
    }

    // -------------------------------------------------------------------------
    // Vecteurs de dimensions différentes
    // -------------------------------------------------------------------------

    public function testHandlesDimensionMismatchGracefully(): void
    {
        $this->store->saveMemory([1.0, 0.0, 0.0], ['text' => '3D']);

        // Vecteur de dimension différente — ne doit pas crasher
        $results = $this->store->searchSimilar([1.0, 0.0], 1);

        $this->assertCount(1, $results);
    }
}
