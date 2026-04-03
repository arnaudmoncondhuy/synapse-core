<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Chunking;

use ArnaudMoncondhuy\SynapseCore\Contract\TextSplitterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Registre des stratégies de découpage de texte.
 */
class TextSplitterRegistry
{
    /** @var array<string, TextSplitterInterface> */
    private array $splitters = [];

    /**
     * @param iterable<string, TextSplitterInterface> $splitters
     */
    public function __construct(
        #[AutowireIterator(tag: 'synapse.text_splitter', indexAttribute: 'key')]
        iterable $splitters,
    ) {
        foreach ($splitters as $alias => $splitter) {
            $this->splitters[$alias] = $splitter;
        }
    }

    public function addSplitter(string $alias, TextSplitterInterface $splitter): void
    {
        $this->splitters[$alias] = $splitter;
    }

    public function getSplitter(string $alias): TextSplitterInterface
    {
        if (!isset($this->splitters[$alias])) {
            $fallback = $this->splitters['recursive'] ?? reset($this->splitters);
            if (false === $fallback) {
                throw new \LogicException('No text splitters registered.');
            }

            return $fallback;
        }

        return $this->splitters[$alias];
    }
}
