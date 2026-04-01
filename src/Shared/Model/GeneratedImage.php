<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Model;

/**
 * Value Object représentant une image générée par un client de génération d'image.
 */
final readonly class GeneratedImage
{
    public function __construct(
        /** Données de l'image encodées en base64 */
        public string $data,

        /** Type MIME (ex: 'image/png', 'image/jpeg') */
        public string $mimeType,

        /** Prompt révisé retourné par le provider, si disponible */
        public ?string $revisedPrompt = null,
    ) {
    }

    /**
     * @return array{mime_type: string, data: string}
     */
    public function toAttachmentArray(): array
    {
        return [
            'mime_type' => $this->mimeType,
            'data' => $this->data,
        ];
    }
}
