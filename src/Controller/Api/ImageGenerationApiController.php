<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Service\ImageGenerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint API pour la génération d'image standalone (hors chat).
 *
 * Permet à l'application hôte de générer des images via n'importe quel
 * provider de génération d'image configuré.
 *
 * POST /synapse/api/image/generate
 * Body JSON:
 *   {
 *     "prompt": "Un chien sur la lune",
 *     "provider": "my_provider",     // optionnel
 *     "options": {                    // optionnel
 *       "width": 1024,
 *       "height": 1024,
 *       "n": 1,
 *       "model": "stable-diffusion-xl",
 *       "negative_prompt": "blurry"
 *     }
 *   }
 *
 * Réponse JSON:
 *   {
 *     "images": [
 *       {"mime_type": "image/png", "data": "base64..."}
 *     ]
 *   }
 */
#[Route('/synapse/api/image')]
class ImageGenerationApiController extends AbstractController
{
    public function __construct(
        private readonly ImageGenerationService $imageGenerationService,
        private readonly PermissionCheckerInterface $permissionChecker,
    ) {
    }

    #[Route('/generate', name: 'synapse_api_image_generate', methods: ['POST'])]
    public function generate(Request $request): JsonResponse
    {
        if (!$this->permissionChecker->canCreateConversation()) {
            throw $this->createAccessDeniedException('Not allowed to generate images.');
        }

        $rawJson = $request->getContent();
        $data = json_decode(is_string($rawJson) ? $rawJson : '{}', true);
        if (!is_array($data)) {
            $data = [];
        }

        $prompt = is_string($data['prompt'] ?? null) ? trim((string) $data['prompt']) : '';
        if ('' === $prompt) {
            return $this->json(['error' => 'Le champ "prompt" est requis.'], 400);
        }

        $provider = is_string($data['provider'] ?? null) ? (string) $data['provider'] : null;

        $rawOptions = $data['options'] ?? [];
        /** @var array{model?: string, width?: int, height?: int, n?: int, negative_prompt?: string} $options */
        $options = is_array($rawOptions) ? $rawOptions : [];

        try {
            $generatedImages = $this->imageGenerationService->generate($prompt, $provider, $options);

            return $this->json([
                'images' => array_map(
                    fn ($img) => $img->toAttachmentArray(),
                    $generatedImages
                ),
            ]);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Erreur lors de la génération : '.$e->getMessage()], 500);
        }
    }

    #[Route('/providers', name: 'synapse_api_image_providers', methods: ['GET'])]
    public function providers(): JsonResponse
    {
        if (!$this->permissionChecker->canCreateConversation()) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'providers' => $this->imageGenerationService->getAvailableProviders(),
        ]);
    }
}
