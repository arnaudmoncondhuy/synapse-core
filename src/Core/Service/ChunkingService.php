<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Service;

use ArnaudMoncondhuy\SynapseCore\Contract\TextSplitterInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Chunking\TextSplitterRegistry;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConfigRepository;

/**
 * Service de haut niveau pour le découpage de documents.
 * 
 * Utilise la stratégie de splitter configurée et les paramètres 
 * globaux (taille, overlap) pour fournir des segments prêts à la vectorisation.
 */
class ChunkingService
{
    public function __construct(
        private TextSplitterRegistry $splitterRegistry,
        private SynapseConfigRepository $configRepository
    ) {}

    /**
     * Découpe un texte en utilisant les réglages globaux du bundle.
     * 
     * @return string[]
     */
    public function chunkText(string $text, ?int $size = null, ?int $overlap = null, ?string $strategy = null): array
    {
        $config = $this->configRepository->getGlobalConfig();

        $resolvedStrategy = $strategy ?? $config->getChunkingStrategy();
        $splitter = $this->splitterRegistry->getSplitter($resolvedStrategy);

        $resolvedSize = $size ?? $config->getChunkSize();
        $resolvedOverlap = $overlap ?? $config->getChunkOverlap();

        return $splitter->splitText($text, $resolvedSize, $resolvedOverlap);
    }

    /**
     * Retourne la stratégie de splitter actuellement utilisée dans la config globale.
     */
    public function getSplitter(): TextSplitterInterface
    {
        $config = $this->configRepository->getGlobalConfig();
        return $this->splitterRegistry->getSplitter($config->getChunkingStrategy());
    }
}
