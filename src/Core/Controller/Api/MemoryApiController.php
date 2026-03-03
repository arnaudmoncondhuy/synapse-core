<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Controller\Api;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Core\Memory\MemoryManager;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MemoryScope;
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
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
    ) {}

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

        $data = json_decode($request->getContent(), true) ?? [];
        $fact = $data['fact'] ?? null;

        if (empty($fact)) {
            return $this->json(['error' => 'Le fait à retenir est requis.'], 400);
        }

        $scopeRaw = $data['scope'] ?? 'user';
        $scope = MemoryScope::tryFrom($scopeRaw) ?? MemoryScope::USER;
        $conversationId = $data['conversation_id'] ?? null;
        $category = $data['category'] ?? 'other';

        // Récupérer l'identifiant de l'utilisateur courant
        $userId = $this->getUserId();

        $this->memoryManager->remember(
            $fact,
            $scope,
            $userId,
            $conversationId,
            'fact'
        );

        return $this->json([
            'success' => true,
            'message' => 'Souvenir enregistré avec succès.',
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

        // Rien à faire côté serveur, on laisse le frontend gérer l'affichage
        return $this->json(['success' => true, 'message' => 'Proposition ignorée.']);
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

        $items = array_map(fn($m) => [
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
        return $id !== null ? (string) $id : null;
    }
}
