<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Manager\ConversationManager;
use ArnaudMoncondhuy\SynapseCore\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * API REST pour la gestion de la mémoire sémantique "Human-in-the-loop".
 *
 * Permet de confirmer, refuser, lister et supprimer des souvenirs proposés par le LLM.
 */
#[Route('/synapse/api/memory')]
class MemoryApiController extends AbstractController
{
    public function __construct(
        private MemoryManager $memoryManager,
        private PermissionCheckerInterface $permissionChecker,
        private ?ConversationManager $conversationManager = null,
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {
    }

    /**
     * Confirme la mémorisation d'un fait proposé par le LLM.
     *
     * Body JSON attendu : { "fact": string, "category": string, "scope": "user"|"conversation", "conversation_id": string|null }
     */
    #[Route('/confirm', name: 'synapse_api_memory_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        $this->checkCsrf($request);

        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $fact = is_string($data['fact'] ?? null) ? (string) $data['fact'] : '';

        if (empty($fact)) {
            return $this->json(['error' => 'Le fait à retenir est requis.'], 400);
        }

        $scopeRaw = is_string($data['scope'] ?? null) ? (string) $data['scope'] : 'user';
        $scope = MemoryScope::tryFrom($scopeRaw) ?? MemoryScope::USER;
        $conversationId = is_string($data['conversation_id'] ?? null) ? (string) $data['conversation_id'] : null;
        $category = is_string($data['category'] ?? null) ? (string) $data['category'] : 'other';

        // Récupérer l'identifiant de l'utilisateur courant
        $userId = $this->getUserId();

        $this->memoryManager->remember(
            $fact,
            $scope,
            $userId,
            $conversationId,
            'fact'
        );

        // Loopback : Ajouter un message à la conversation si ID présent
        $feedbackMessage = null;
        if ($conversationId && $this->conversationManager) {
            $user = $this->getUser();
            if ($user instanceof ConversationOwnerInterface) {
                $conversation = $this->conversationManager->getConversation($conversationId, $user);
                if ($conversation) {
                    $feedbackMessage = sprintf("✅ J'ai validé la mémorisation de l'information : %s", $fact);
                    $this->conversationManager->saveMessage(
                        $conversation,
                        MessageRole::USER,
                        $feedbackMessage,
                        ['metadata' => ['subtype' => 'system_action', 'action' => 'memory_confirmed']]
                    );
                }
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Souvenir enregistré avec succès.',
            'feedback_message' => $feedbackMessage,
        ]);
    }

    /**
     * Rejette une proposition de mémorisation (ne stocke rien).
     */
    #[Route('/reject', name: 'synapse_api_memory_reject', methods: ['POST'])]
    public function reject(Request $request): JsonResponse
    {
        $this->checkCsrf($request);

        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $conversationId = is_string($data['conversation_id'] ?? null) ? (string) $data['conversation_id'] : null;
        $fact = is_string($data['fact'] ?? null) ? (string) $data['fact'] : 'une information';

        // Loopback : Ajouter un message de rejet à la conversation
        $feedbackMessage = null;
        if ($conversationId && $this->conversationManager) {
            $user = $this->getUser();
            if ($user instanceof ConversationOwnerInterface) {
                $conversation = $this->conversationManager->getConversation($conversationId, $user);
                if ($conversation) {
                    $feedbackMessage = sprintf("❌ Je refuse la mémorisation de l'information : %s", $fact);
                    $this->conversationManager->saveMessage(
                        $conversation,
                        MessageRole::USER,
                        $feedbackMessage,
                        ['metadata' => ['subtype' => 'system_action', 'action' => 'memory_rejected']]
                    );
                }
            }
        }

        return $this->json([
            'success' => true,
            'message' => 'Proposition ignorée.',
            'feedback_message' => $feedbackMessage,
        ]);
    }

    /**
     * Liste les souvenirs de l'utilisateur courant.
     */
    #[Route('', name: 'synapse_api_memory_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $userId = $this->getUserId();

        if (!$userId) {
            return $this->json(['error' => 'Utilisateur non identifié.'], 401);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $memories = $this->memoryManager->listForUser($userId, $page);

        $items = array_map(fn ($m) => [
            'id' => $m->getId(),
            'content' => $m->getContent(),
            'scope' => $m->getScope(),
            'source_type' => $m->getSourceType(),
            'conversation_id' => $m->getConversationId(),
            'created_at' => $m->getCreatedAt()->format('c'),
        ], $memories);

        return $this->json(['memories' => $items, 'page' => $page]);
    }

    /**
     * Crée manuellement un nouveau souvenir (saisi par l'utilisateur).
     */
    #[Route('/manual', name: 'synapse_api_memory_create_manual', methods: ['POST'])]
    public function createManual(Request $request): JsonResponse
    {
        $this->checkCsrf($request);

        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $fact = trim(is_string($data['fact'] ?? null) ? (string) $data['fact'] : '');

        if (empty($fact)) {
            return $this->json(['error' => 'Le texte du souvenir est requis.'], 400);
        }

        $userId = $this->getUserId();

        // On force le scope 'user' et 'manual' comme source
        $this->memoryManager->remember(
            $fact,
            MemoryScope::USER,
            $userId,
            null,
            'manual'
        );

        return $this->json([
            'success' => true,
            'message' => 'Souvenir créé avec succès.',
        ]);
    }

    /**
     * Met à jour le texte d'un souvenir spécifique.
     */
    #[Route('/{id}', name: 'synapse_api_memory_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $this->checkCsrf($request);

        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($request->getContent(), true) ?? [];
        $newText = trim(is_string($data['fact'] ?? null) ? (string) $data['fact'] : '');

        if (empty($newText)) {
            return $this->json(['error' => 'Le nouveau texte est requis.'], 400);
        }

        $userId = $this->getUserId();

        try {
            $this->memoryManager->update($id, $newText, $userId);

            return $this->json(['success' => true, 'message' => 'Souvenir mis à jour.']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 403);
        }
    }

    /**
     * Supprime un souvenir spécifique (RGPD / Privacy Dashboard).
     */
    #[Route('/{id}', name: 'synapse_api_memory_delete', methods: ['DELETE'])]
    public function delete(Request $request, int $id): JsonResponse
    {
        $this->checkCsrf($request);

        if (!$this->permissionChecker->canCreateConversation()) {
            return $this->json(['error' => 'Access denied.'], 403);
        }

        $userId = $this->getUserId();

        try {
            $this->memoryManager->forget($id, $userId);

            return $this->json(['success' => true, 'message' => 'Souvenir supprimé.']);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], 403);
        }
    }

    private function checkCsrf(Request $request): void
    {
        if (!$this->getParameter('synapse.security.api_csrf_enabled') || !$this->csrfTokenManager) {
            return;
        }
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->get('_csrf_token');
        if (!$this->isCsrfTokenValid('synapse_api', (string) $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    /**
     * Retourne l'identifiant de l'utilisateur courant sous forme de string.
     */
    private function getUserId(): ?string
    {
        $user = $this->getUser();

        if (!$user instanceof ConversationOwnerInterface) {
            return null;
        }

        // ConversationOwnerInterface::getId() — compatible UUIDs et entiers
        $id = $user->getId();

        return null !== $id ? (string) $id : null;
    }
}
