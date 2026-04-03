<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Service;

use ArnaudMoncondhuy\SynapseCore\Contract\ImageGenerationClientInterface;
use ArnaudMoncondhuy\SynapseCore\Shared\Model\GeneratedImage;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Service de génération d'image standalone.
 *
 * Orchestre la sélection du client image et la génération.
 * Utilisable par l'app hôte indépendamment du chat.
 *
 * Usage :
 *   $images = $imageGenerationService->generate('Un chien sur la lune', 'my_provider');
 *   foreach ($images as $image) {
 *       file_put_contents('output.png', base64_decode($image->data));
 *   }
 */
class ImageGenerationService
{
    /** @var array<string, ImageGenerationClientInterface> */
    private array $clientMap = [];

    /**
     * @param iterable<ImageGenerationClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('synapse.image_generation_client')]
        iterable $clients,
    ) {
        foreach ($clients as $client) {
            $this->clientMap[$client->getProviderName()] = $client;
        }
    }

    /**
     * Génère des images depuis un prompt texte.
     *
     * @param string $prompt Description textuelle de l'image
     * @param string|null $provider Slug du provider. Si null, utilise le premier disponible.
     * @param array{
     *     model?: string,
     *     width?: int,
     *     height?: int,
     *     n?: int,
     *     negative_prompt?: string
     * } $options Options de génération
     *
     * @return list<GeneratedImage>
     */
    public function generate(string $prompt, ?string $provider = null, array $options = []): array
    {
        $client = $this->resolveClient($provider);

        return $client->generateImage($prompt, $options);
    }

    /**
     * Liste les providers de génération d'image disponibles.
     *
     * @return string[]
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->clientMap);
    }

    private function resolveClient(?string $provider): ImageGenerationClientInterface
    {
        if (null === $provider) {
            $first = reset($this->clientMap);
            if (false === $first) {
                throw new \RuntimeException('No image generation client available. Please configure an image provider.');
            }

            return $first;
        }

        if (!isset($this->clientMap[$provider])) {
            throw new \RuntimeException(sprintf('Image generation provider "%s" not found. Available: %s', $provider, implode(', ', array_keys($this->clientMap))));
        }

        return $this->clientMap[$provider];
    }
}
