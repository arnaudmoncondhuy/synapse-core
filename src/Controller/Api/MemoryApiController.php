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
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private ?TranslatorInterface $translator = null,
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
            $msg = $this->translator ? $this->translator->trans('synapse.core.api.memory.error.fact_required', [], 'synapse_core') : 'Le fait à retenir est requis.';
            return $this->json(['error' => $msg], 400);
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
                    $feedbackMessage = $this->translator 
                        ? $this->translator->trans('synapse.core.api.memory.feedback.confirmed', ['%fact%' => $fact], 'synapse_core')
                        : sprintf("✅ J'ai validé la mémorisation de l'information : %s", $fact);
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
            'message' => $this->translator ? $this->translator->trans('synapse.core.api.memory.success.recorded', [], 'synapse_core') : 'Souvenir enregistré avec succès.',
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
        $factFallback = $this->translator ? $this->translator->trans('synapse.core.api.memory.fact_fallback', [], 'synapse_core') : 'une information';
        $fact = is_string($data['fact'] ?? null) ? (string) $data['fact'] : $factFallback;

        // Loopback : Ajouter un message de rejet à la conversation
        $feedbackMessage = null;
        if ($conversationId && $this->conversationManager) {
            $user = $this->getUser();
            if ($user instanceof ConversationOwnerInterface) {
                $conversation = $this->conversationManager->getConversation($conversationId, $user);
                if ($conversation) {
                    $feedbackMessage = $this->translator 
                        ? $this->translator->trans('synapse.core.api.memory.feedback.rejected', ['%fact%' => $fact], 'synapse_core')
                        : sprintf("❌ Je refuse la mémorisation de l'information : %s", $fact);
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
            'message' => $this->translator ? $this->translator->trans('synapse.core.api.memory.success.ignored', [], 'synapse_core') : 'Proposition ignorée.',
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
            $msg = $this->translator ? $this->translator->trans('synapse.core.api.error.user_not_identified', [], 'synapse_core') : 'Utilisateur non identifié.';
            return $this->json(['error' => $msg], 401);
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
            $msg = $this->translator ? $this->translator->trans('synapse.core.api.memory.error.fact_required', [], 'synapse_core') : 'Le texte du souvenir est requis.';
            return $this->json(['error' => $msg], 400);
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
            'message' => $this->translator ? $this->translator->trans('synapse.core.api.memory.success.created', [], 'synapse_core') : 'Souvenir créé avec succès.',
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
            $msg = $this->translator ? $this->translator->trans('synapse.core.api.memory.error.text_required', [], 'synapse_core') : 'Le nouveau texte est requis.';
            return $this->json(['error' => $msg], 400);
        }

        $userId = $this->getUserId();

        try {
            $this->memoryManager->update($id, $newText, $userId);

            $msg = $this->translator ? $this->translator->trans('synapse.core.api.memory.success.updated', [], 'synapse_core') : 'Souvenir mis à jour.';
            return $this->json(['success' => true, 'message' => $msg]);
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

            $msg = $this->translator ? $this->translator->trans('synapse.core.api.memory.success.deleted', [], 'synapse_core') : 'Souvenir supprimé.';
            return $this->json(['success' => true, 'message' => $msg]);
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
            $msg = $this->translator ? $this->translator->trans('synapse.core.api.error.csrf_invalid', [], 'synapse_core') : 'Invalid CSRF token.';
            throw $this->createAccessDeniedException($msg);
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
