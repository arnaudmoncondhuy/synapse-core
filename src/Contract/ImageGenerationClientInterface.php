<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Contract;

use ArnaudMoncondhuy\SynapseCore\Shared\Model\GeneratedImage;

/**
 * Contrat pour les clients de génération d'image standalone.
 *
 * Distinct de LlmClientInterface — la génération d'image est un paradigme
 * différent du chat (pas de messages, pas d'historique, juste prompt → image).
 */
interface ImageGenerationClientInterface
{
    /**
     * Identifiant unique du provider (ex: 'ovh').
     */
    public function getProviderName(): string;

    /**
     * Génère une ou plusieurs images depuis un prompt texte.
     *
     * @param string $prompt Description textuelle de l'image souhaitée
     * @param array{
     *     model?: string,
     *     width?: int,
     *     height?: int,
     *     n?: int,
     *     negative_prompt?: string
     * } $options Options de génération (dépendent du provider)
     *
     * @return list<GeneratedImage> Images générées (base64 + mime_type)
     */
    public function generateImage(string $prompt, array $options = []): array;

    /**
     * Champs de credentials requis pour l'admin UI.
     *
     * @return array<int, array{name: string, label: string, type: string, required: bool}>
     */
    public function getCredentialFields(): array;
}
