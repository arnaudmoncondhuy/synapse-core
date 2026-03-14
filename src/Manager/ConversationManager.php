<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Manager;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Service\AttachmentStorageService;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Gestionnaire centralisé des conversations et de leur persistance.
 *
 * Responsabilités :
 * - Cycle de vie des conversations (CRUD) avec chiffrement transparent des données sensibles.
 * - Gestion des messages et calcul automatique des coûts/tokens.
 * - Vérification des permissions d'accès (via `PermissionCheckerInterface`).
 * - Gestion du contexte thread-local pour suivre la conversation active.
 *
 * @see SynapseConversation
 */
class ConversationManager
{
    private ?SynapseConversation $currentConversation = null;

    /** @var SynapseConversationRepository<SynapseConversation>|null */
    private ?SynapseConversationRepository $resolvedConversationRepo = null;

    public function __construct(
        private EntityManagerInterface $em,
        /** @var SynapseConversationRepository<SynapseConversation>|null */
        private ?SynapseConversationRepository $conversationRepo = null,
        private ?EncryptionServiceInterface $encryptionService = null,
        private ?PermissionCheckerInterface $permissionChecker = null,
        /** @var class-string<SynapseConversation>|null */
        private ?string $conversationClass = null,
        /** @var class-string<SynapseMessage>|null */
        private ?string $messageClass = null,
        private ?AttachmentStorageService $attachmentStorage = null,
    ) {
    }

    /**
     * Récupère le repository de conversations (injecté ou résolu dynamiquement).
     *
     * @return SynapseConversationRepository<SynapseConversation>
     */
    private function getConversationRepo(): SynapseConversationRepository
    {
        if (null !== $this->conversationRepo) {
            return $this->conversationRepo;
        }

        if (null === $this->resolvedConversationRepo) {
            /** @var SynapseConversationRepository<SynapseConversation> $repo */
            $repo = $this->em->getRepository($this->getConversationClass());
            $this->resolvedConversationRepo = $repo;
        }

        return $this->resolvedConversationRepo;
    }

    /**
     * Crée une nouvelle conversation.
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param string|null $title Titre (sera chiffré si encryption activée)
     *
     * @return SynapseConversation Nouvelle conversation
     */
    public function createConversation(
        ConversationOwnerInterface $owner,
        ?string $title = null,
    ): SynapseConversation {
        $conversation = $this->instantiateConversation();
        $conversation->setOwner($owner);

        if (null !== $title) {
            $this->setTitle($conversation, $title);
        }

        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    /**
     * Met à jour le titre d'une conversation.
     *
     * @param SynapseConversation $conversation SynapseConversation
     * @param string $title Nouveau titre (sera chiffré si encryption activée)
     */
    public function updateTitle(SynapseConversation $conversation, string $title): void
    {
        $this->checkPermission($conversation, 'edit');
        $this->setTitle($conversation, $title);
        $this->em->flush();
    }

    /**
     * Enregistre un nouveau message dans une conversation.
     *
     * Si le chiffrement est activé, le contenu et les métadonnées sensibles sont chiffrés
     * avant la persistance.
     *
     * Le coût en tokens n'est plus calculé ici — il est dans SynapseLlmCall (via TokenAccountingService::logUsage()).
     * Pour les messages MODEL, passer le callId retourné par logUsage() afin de relier
     * le message à son enregistrement LLM exact.
     *
     * @param SynapseConversation $conversation la conversation concernée
     * @param MessageRole $role Rôle de l'émetteur (USER, MODEL, etc.).
     * @param string $content contenu textuel brut
     * @param array{
     *     prompt_tokens?: int,
     *     completion_tokens?: int,
     *     thinking_tokens?: int,
     *     safety_ratings?: array<string, array{category: string, probability: string}>,
     *     blocked?: bool,
     *     model?: string,
     *     preset_id?: int|null,
     *     metadata?: array<string, mixed>
     * } $metadata Données techniques de l'échange
     * @param string|null $callId UUID de l'appel LLM (SynapseLlmCall.callId) — pour les messages MODEL.
     * @param array<int, array{mime_type: string, data: string}> $images images à stocker comme pièces jointes
     *
     * @return SynapseMessage L'entité message créée
     */
    public function saveMessage(
        SynapseConversation $conversation,
        MessageRole $role,
        string $content,
        array $metadata = [],
        ?string $callId = null,
        array $images = [],
    ): SynapseMessage {
        $message = $this->instantiateMessage();
        $message->setConversation($conversation);
        $message->setRole($role);
        $this->setMessageContent($message, $content);

        // Lier le message à son appel LLM (pour les messages MODEL)
        if (null !== $callId) {
            $message->setLlmCallId($callId);
        }

        // Tokens
        if (isset($metadata['prompt_tokens'])) {
            $message->setPromptTokens($metadata['prompt_tokens']);
        }
        if (isset($metadata['completion_tokens'])) {
            $message->setCompletionTokens($metadata['completion_tokens']);
        }
        if (isset($metadata['thinking_tokens'])) {
            $message->setThinkingTokens($metadata['thinking_tokens']);
        }
        if (isset($metadata['safety_ratings'])) {
            $message->setSafetyRatings($metadata['safety_ratings']);
        }
        if (isset($metadata['blocked'])) {
            $message->setBlocked($metadata['blocked']);
        }
        if (isset($metadata['metadata'])) {
            $metaDataToSave = $metadata['metadata'];
            if (null !== $this->encryptionService) {
                $encryptedMeta = $this->encryptionService->encrypt(json_encode($metaDataToSave, JSON_THROW_ON_ERROR));
                $message->setMetadata(['_encrypted' => $encryptedMeta]);
            } else {
                $message->setMetadata($metaDataToSave);
            }
        }

        // Preset utilisé (pour analytics)
        if (array_key_exists('preset_id', $metadata)) {
            $message->setMetadataValue('preset_id', $metadata['preset_id']);
        }

        // Modèle utilisé (pour affichage)
        if (isset($metadata['model'])) {
            $message->setMetadataValue('model', $metadata['model']);
        }

        // Calculer total tokens
        $message->calculateTotalTokens();

        // Éviter les doublons si l'objet est déjà dans la collection
        if (!$conversation->getMessages()->contains($message)) {
            $conversation->addMessage($message);
        }
        $this->em->persist($message);

        // Store images as file attachments (replaces base64 storage in metadata)
        if (!empty($images) && null !== $this->attachmentStorage) {
            foreach ($images as $image) {
                $this->attachmentStorage->store($image, $message->getId(), $conversation->getId());
            }
        }

        $this->em->flush();

        return $message;
    }

    /**
     * Récupère une conversation avec vérification des permissions.
     *
     * @param string $id ID de la conversation
     * @param ConversationOwnerInterface|null $owner Propriétaire (optionnel, pour filtrer)
     *
     * @throws AccessDeniedException Si pas de permission
     *
     * @return SynapseConversation|null SynapseConversation ou null si non trouvée
     */
    public function getConversation(string $id, ?ConversationOwnerInterface $owner = null): ?SynapseConversation
    {
        $conversation = $this->getConversationRepo()->find($id);

        if (null === $conversation || $conversation->isDeleted()) {
            return null;
        }

        // Vérifier ownership si fourni
        if (null !== $owner && $conversation->getOwner()?->getId() !== $owner->getId()) {
            throw new AccessDeniedException('Access denied to this conversation');
        }

        // Vérifier permission
        $this->checkPermission($conversation, 'view');

        return $conversation;
    }

    /**
     * Récupère les conversations d'un utilisateur avec déchiffrement des titres.
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param ConversationStatus|null $status Filtrer par statut
     * @param int $limit Nombre maximum de résultats
     *
     * @return SynapseConversation[] Conversations avec titres déchiffrés
     */
    public function getUserConversations(
        ConversationOwnerInterface $owner,
        ?ConversationStatus $status = null,
        int $limit = 50,
    ): array {
        if (null !== $status) {
            $conversations = $this->getConversationRepo()->findBy(
                ['owner' => $owner, 'status' => $status],
                ['updatedAt' => 'DESC'],
                $limit
            );
        } else {
            $conversations = $this->getConversationRepo()->findActiveByOwner($owner, $limit);
        }

        // Déchiffrer les titres
        foreach ($conversations as $conversation) {
            if (null !== $conversation->getTitle() && null !== $this->encryptionService) {
                if ($this->encryptionService->isEncrypted($conversation->getTitle())) {
                    $decrypted = $this->encryptionService->decrypt($conversation->getTitle());
                    $conversation->setTitle($decrypted);
                }
            }
        }

        return $conversations;
    }

    /**
     * Récupère toutes les conversations (accès administrateur Break-Glass).
     *
     * Aucun filtrage par owner — réservé à l'administration uniquement.
     * Toujours auditer cet accès dans le contrôleur appelant.
     *
     * @param int $limit Nombre maximum de résultats par page
     * @param int $offset Décalage pour la pagination
     *
     * @return SynapseConversation[]
     */
    public function getAllConversations(int $limit = 50, int $offset = 0): array
    {
        return $this->getConversationRepo()->findBy(
            [],
            ['createdAt' => 'DESC'],
            $limit,
            $offset,
        );
    }

    /**
     * Compte toutes les conversations (accès administrateur Break-Glass).
     */
    public function countAllConversations(): int
    {
        return $this->getConversationRepo()->count([]);
    }

    /**
     * Récupère les messages d'une conversation avec déchiffrement.
     *
     * @param SynapseConversation $conversation SynapseConversation
     * @param int $limit Nombre maximum de messages (0 = tous)
     *
     * @return SynapseMessage[] Messages déchiffrés
     */
    public function getMessages(SynapseConversation $conversation, int $limit = 0): array
    {
        $this->checkPermission($conversation, 'view');

        $messageClass = $this->getMessageClass();
        /** @var \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageRepository<SynapseMessage> $messageRepo */
        $messageRepo = $this->em->getRepository($messageClass);
        $messages = $messageRepo->findByConversation($conversation, $limit);

        // FIX: Convert potential Doctrine Collection or iterable to plain array
        if (!is_array($messages)) {
            $messages = array_values($messages instanceof \Traversable ? iterator_to_array($messages) : (array) $messages);
        }

        // Déchiffrer les contenus ou normaliser
        foreach ($messages as $message) {
            if (null !== $this->encryptionService && $this->encryptionService->isEncrypted($message->getContent())) {
                $decrypted = $this->encryptionService->decrypt($message->getContent());
                $message->setDecryptedContent($decrypted);
            } else {
                // Fallback explicite pour les messages non chiffrés
                $message->setDecryptedContent($message->getContent());
            }

            // Déchiffrement des métadonnées
            $meta = $message->getMetadata();
            if (isset($meta['_encrypted']) && is_string($meta['_encrypted']) && null !== $this->encryptionService) {
                try {
                    $decryptedMeta = json_decode($this->encryptionService->decrypt($meta['_encrypted']), true, 512, JSON_THROW_ON_ERROR);
                    /** @var array<string, mixed>|null $finalMeta */
                    $finalMeta = is_array($decryptedMeta) ? $decryptedMeta : null;
                    $message->setMetadata($finalMeta);
                } catch (\Exception $e) {
                    // Si échec de déchiffrement, on vide les métadonnées par sécurité
                    $message->setMetadata([]);
                }
            }
        }

        // CRITICAL FIX: Clone objects to detach from Doctrine session
        // Doctrine converts entities to arrays when accessed in closure context - cloning breaks this
        $clonedMessages = [];
        foreach ($messages as $msg) {
            $clonedMessages[] = clone $msg;
        }

        return $clonedMessages;
    }

    /**
     * Supprime une conversation (soft delete).
     *
     * @param SynapseConversation $conversation SynapseConversation à supprimer
     */
    public function deleteConversation(SynapseConversation $conversation): void
    {
        $this->checkPermission($conversation, 'delete');

        // Supprimer les fichiers d'attachments avant le soft delete
        // (le soft delete ne déclenche pas les cascades SQL ni les events Doctrine)
        if (null !== $this->attachmentStorage) {
            foreach ($conversation->getMessages() as $message) {
                $this->attachmentStorage->deleteByMessageId($message->getId());
            }
        }

        $conversation->softDelete();
        $this->em->flush();
    }

    /**
     * Retourne les messages d'une conversation formatés en tableau pour le rendu Twig.
     *
     * Gère les deux formats possibles (objet SynapseMessage ou tableau legacy).
     * Filtre les messages non affichables (système, fonction, etc.).
     *
     * @param SynapseConversation $conversation SynapseConversation à formater
     * @param bool $forDisplay When true (Twig rendering), includes attachments info.
     *                         When false (LLM), replaces image content with placeholder text.
     *
     * @return array<int, array{role: string, content: array<mixed>|string, metadata: array<string, mixed>, attachments?: array<int, array{uuid: string, mime_type: string}>}>
     */
    public function getHistoryArray(SynapseConversation $conversation, bool $forDisplay = false): array
    {
        $history = [];
        $messages = $this->getMessages($conversation);

        foreach ($messages as $msg) {
            if (!$msg->isDisplayable()) {
                continue;
            }
            $role = $msg->getRole()->value;
            $textContent = $msg->getDecryptedContent() ?? $msg->getContent();
            $metadata = $msg->getMetadata() ?? [];

            // Load attachments via repository (pas de relation Doctrine directe — SynapseMessage est abstraite)
            $attachments = [];
            $attachmentEntities = $this->em->getRepository(SynapseMessageAttachment::class)->findBy(['messageId' => $msg->getId()]);
            foreach ($attachmentEntities as $att) {
                $attachments[] = ['uuid' => $att->getId(), 'mime_type' => $att->getMimeType()];
            }

            if ($forDisplay) {
                // For Twig: return plain text content + attachments array for display
                $entry = [
                    'role' => $role,
                    'content' => $textContent,
                    'metadata' => $metadata,
                ];
                if (!empty($attachments)) {
                    $entry['attachments'] = $attachments;
                }
            } else {
                // For LLM: legacy parts from metadata, or text with placeholder for images
                $contentForHistory = isset($metadata['parts']) && is_array($metadata['parts'])
                    ? $metadata['parts']
                    : $textContent;

                // If there are file attachments and no legacy parts, add placeholders
                if (!empty($attachments) && !isset($metadata['parts'])) {
                    $placeholders = [];
                    foreach ($attachments as $att) {
                        $placeholders[] = '[Image jointe : '.$att['mime_type'].']';
                    }
                    $contentForHistory = $textContent."\n".implode("\n", $placeholders);
                }

                $entry = [
                    'role' => $role,
                    'content' => $contentForHistory,
                    'metadata' => $metadata,
                ];
            }

            $history[] = $entry;
        }

        return $history;
    }

    /**
     * Définit la conversation courante (contexte thread-local).
     *
     * @param SynapseConversation|null $conversation SynapseConversation courante
     */
    public function setCurrentConversation(?SynapseConversation $conversation): void
    {
        $this->currentConversation = $conversation;
    }

    /**
     * Récupère la conversation courante.
     *
     * @return SynapseConversation|null SynapseConversation courante ou null
     */
    public function getCurrentConversation(): ?SynapseConversation
    {
        return $this->currentConversation;
    }

    // Méthodes privées

    /**
     * Définit le titre d'une conversation avec chiffrement transparent.
     */
    private function setTitle(SynapseConversation $conversation, string $title): void
    {
        if (null !== $this->encryptionService) {
            $title = $this->encryptionService->encrypt($title);
        }
        $conversation->setTitle($title);
    }

    /**
     * Définit le contenu d'un message avec chiffrement transparent.
     */
    private function setMessageContent(SynapseMessage $message, string $content): void
    {
        $message->setDecryptedContent($content);

        if (null !== $this->encryptionService) {
            $content = $this->encryptionService->encrypt($content);
        }
        $message->setContent($content);
    }

    /**
     * Vérifie les permissions sur une conversation.
     *
     * @throws AccessDeniedException Si pas de permission
     */
    private function checkPermission(SynapseConversation $conversation, string $action): void
    {
        if (null === $this->permissionChecker) {
            return; // Pas de vérification si pas de checker
        }

        $allowed = match ($action) {
            'view' => $this->permissionChecker->canView($conversation),
            'edit' => $this->permissionChecker->canEdit($conversation),
            'delete' => $this->permissionChecker->canDelete($conversation),
            default => false,
        };

        if (!$allowed) {
            throw new AccessDeniedException("Access denied: cannot {$action} this conversation");
        }
    }

    /**
     * Instancie une nouvelle conversation.
     *
     * À override dans les projets si classe custom
     */
    protected function instantiateConversation(): SynapseConversation
    {
        $class = $this->getConversationClass();

        return new $class();
    }

    /**
     * Instancie un nouveau message.
     *
     * À override dans les projets si classe custom
     */
    protected function instantiateMessage(): SynapseMessage
    {
        $class = $this->getMessageClass();

        return new $class();
    }

    /**
     * Retourne la classe SynapseConversation à utiliser.
     *
     * @return class-string<SynapseConversation>
     */
    protected function getConversationClass(): string
    {
        return $this->conversationClass ?? SynapseConversation::class;
    }

    /**
     * Retourne la classe SynapseMessage à utiliser.
     *
     * @return class-string<SynapseMessage>
     */
    protected function getMessageClass(): string
    {
        return $this->messageClass ?? SynapseMessage::class;
    }
}
