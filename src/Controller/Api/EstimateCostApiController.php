<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Accounting\TokenCostEstimator;
use ArnaudMoncondhuy\SynapseCore\Formatter\MessageFormatter;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API d'estimation du coût d'une requête avant envoi.
 */
#[Route('%synapse.chat_api_prefix%')]
class EstimateCostApiController extends AbstractController
{
    public function __construct(
        private TokenCostEstimator $tokenCostEstimator,
        private PermissionCheckerInterface $permissionChecker,
        private ?ConversationManager $conversationManager = null,
        private ?MessageFormatter $messageFormatter = null,
    ) {}

    #[Route('/estimate-cost', name: 'synapse_api_estimate_cost', methods: ['POST'])]
    public function estimateCost(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $data = is_array($payload) ? $payload : [];
        $modelId = is_string($data['model_id'] ?? null) ? (string) $data['model_id'] : 'default';

        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $message = trim((string) (is_string($data['message'] ?? null) ? (string) $data['message'] : ''));
        $conversationId = is_string($data['conversation_id'] ?? null) ? (string) $data['conversation_id'] : null;

        $contents = [];

        if ($conversationId && $this->conversationManager && $this->messageFormatter) {
            $user = $this->getUser();
            if ($user instanceof ConversationOwnerInterface) {
                $conversation = $this->conversationManager->getConversation($conversationId, $user);
                if (null !== $conversation) {
                    $dbMessages = $this->conversationManager->getMessages($conversation);
                    $contents = $this->messageFormatter->entitiesToApiFormat($dbMessages);
                }
            }
        }

        if ('' !== $message) {
            $contents[] = ['role' => 'user', 'content' => $message];
        }

        if (empty($contents)) {
            return $this->json([
                'prompt_tokens' => 0,
                'estimated_output_tokens' => 2048,
                'cost_model_currency' => 0.0,
                'cost_reference' => 0.0,
                'currency' => 'USD',
            ]);
        }

        /** @var array<int, array{role: string, content?: string|null}> $contents */
        $totalCost = $this->tokenCostEstimator->estimateCost($contents, $modelId);

        return $this->json($totalCost);
    }
}
