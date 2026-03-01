<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Manager;

use ArnaudMoncondhuy\SynapseCore\Contract\ConversationOwnerInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\PermissionCheckerInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\ConversationStatus;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
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
 * @see \ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation
 */
class ConversationManager
{
    private ?SynapseConversation $currentConversation = null;
    private ?SynapseConversationRepository $resolvedConversationRepo = null;

    public function __construct(
        private EntityManagerInterface $em,
        private ?SynapseConversationRepository $conversationRepo = null,
        private ?EncryptionServiceInterface $encryptionService = null,
        private ?PermissionCheckerInterface $permissionChecker = null,
        private ?string $conversationClass = null,
        private ?string $messageClass = null,
    ) {}

    /**
     * Récupère le repository de conversations (injecté ou résolu dynamiquement)
     */
    private function getConversationRepo(): SynapseConversationRepository
    {
        if ($this->conversationRepo !== null) {
            return $this->conversationRepo;
        }

        if ($this->resolvedConversationRepo === null) {
            /** @var SynapseConversationRepository $repo */
            $repo = $this->em->getRepository($this->getConversationClass());
            $this->resolvedConversationRepo = $repo;
        }

        return $this->resolvedConversationRepo;
    }

    /**
     * Crée une nouvelle conversation
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param string|null $title Titre (sera chiffré si encryption activée)
     * @return SynapseConversation Nouvelle conversation
     */
    public function createConversation(
        ConversationOwnerInterface $owner,
        ?string $title = null
    ): SynapseConversation {
        $conversation = $this->instantiateConversation();
        $conversation->setOwner($owner);

        if ($title !== null) {
            $this->setTitle($conversation, $title);
        }

        $this->em->persist($conversation);
        $this->em->flush();

        return $conversation;
    }

    /**
     * Met à jour le titre d'une conversation
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
     * @param SynapseConversation $conversation La conversation concernée.
     * @param MessageRole         $role         Rôle de l'émetteur (USER, MODEL, etc.).
     * @param string              $content      Contenu textuel brut.
     * @param array{
     *     prompt_tokens?: int,
     *     completion_tokens?: int,
     *     thinking_tokens?: int,
     *     safety_ratings?: array,
     *     blocked?: bool,
     *     model?: string,
     *     preset_id?: int|null,
     *     metadata?: array
     * } $metadata Données techniques de l'échange.
     * @param string|null $callId UUID de l'appel LLM (SynapseLlmCall.callId) — pour les messages MODEL.
     *
     * @return SynapseMessage L'entité message créée.
     */
    public function saveMessage(
        SynapseConversation $conversation,
        MessageRole $role,
        string $content,
        array $metadata = [],
        ?string $callId = null,
    ): SynapseMessage {
        $message = $this->instantiateMessage();
        $message->setConversation($conversation);
        $message->setRole($role);
        $this->setMessageContent($message, $content);

        // Lier le message à son appel LLM (pour les messages MODEL)
        if ($callId !== null) {
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
            if ($this->encryptionService !== null) {
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
        $this->em->flush();

        return $message;
    }

    /**
     * Récupère une conversation avec vérification des permissions
     *
     * @param string $id ID de la conversation
     * @param ConversationOwnerInterface|null $owner Propriétaire (optionnel, pour filtrer)
     * @return SynapseConversation|null SynapseConversation ou null si non trouvée
     * @throws AccessDeniedException Si pas de permission
     */
    public function getConversation(string $id, ?ConversationOwnerInterface $owner = null): ?SynapseConversation
    {
        $conversation = $this->getConversationRepo()->find($id);

        if ($conversation === null) {
            return null;
        }

        // Vérifier ownership si fourni
        if ($owner !== null && $conversation->getOwner()->getId() !== $owner->getId()) {
            throw new AccessDeniedException('Access denied to this conversation');
        }

        // Vérifier permission
        $this->checkPermission($conversation, 'view');

        return $conversation;
    }

    /**
     * Récupère les conversations d'un utilisateur avec déchiffrement des titres
     *
     * @param ConversationOwnerInterface $owner Propriétaire
     * @param ConversationStatus|null $status Filtrer par statut
     * @param int $limit Nombre maximum de résultats
     * @return SynapseConversation[] Conversations avec titres déchiffrés
     */
    public function getUserConversations(
        ConversationOwnerInterface $owner,
        ?ConversationStatus $status = null,
        int $limit = 50
    ): array {
        if ($status !== null) {
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
            if ($conversation->getTitle() !== null && $this->encryptionService !== null) {
                if ($this->encryptionService->isEncrypted($conversation->getTitle())) {
                    $decrypted = $this->encryptionService->decrypt($conversation->getTitle());
                    $conversation->setTitle($decrypted);
                }
            }
        }

        return $conversations;
    }

    /**
     * Récupère toutes les conversations (accès administrateur Break-Glass)
     *
     * Aucun filtrage par owner — réservé à l'administration uniquement.
     * Toujours auditer cet accès dans le contrôleur appelant.
     *
     * @param int $limit   Nombre maximum de résultats par page
     * @param int $offset  Décalage pour la pagination
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
     * Compte toutes les conversations (accès administrateur Break-Glass)
     */
    public function countAllConversations(): int
    {
        return $this->getConversationRepo()->count([]);
    }

    /**
     * Récupère les messages d'une conversation avec déchiffrement
     *
     * @param SynapseConversation $conversation SynapseConversation
     * @param int $limit Nombre maximum de messages (0 = tous)
     * @return SynapseMessage[] Messages déchiffrés
     */
    public function getMessages(SynapseConversation $conversation, int $limit = 0): array
    {
        $this->checkPermission($conversation, 'view');

        $messageClass = $this->getMessageClass();
        /** @var \ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseMessageRepository $messageRepo */
        $messageRepo = $this->em->getRepository($messageClass);
        $messages = $messageRepo->findByConversation($conversation, $limit);

        // FIX: Convert potential Doctrine Collection or iterable to plain array
        if (!is_array($messages)) {
            $messages = array_values($messages instanceof \Traversable ? iterator_to_array($messages) : (array)$messages);
        }

        // Déchiffrer les contenus ou normaliser
        foreach ($messages as $message) {
            if ($this->encryptionService !== null && $this->encryptionService->isEncrypted($message->getContent())) {
                $decrypted = $this->encryptionService->decrypt($message->getContent());
                $message->setDecryptedContent($decrypted);
            } else {
                // Fallback explicite pour les messages non chiffrés
                $message->setDecryptedContent($message->getContent());
            }

            // Déchiffrement des métadonnées
            $meta = $message->getMetadata();
            if (isset($meta['_encrypted']) && $this->encryptionService !== null) {
                try {
                    $decryptedMeta = json_decode($this->encryptionService->decrypt($meta['_encrypted']), true, 512, JSON_THROW_ON_ERROR);
                    $message->setMetadata($decryptedMeta);
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
     * Supprime une conversation (soft delete)
     *
     * @param SynapseConversation $conversation SynapseConversation à supprimer
     */
    public function deleteConversation(SynapseConversation $conversation): void
    {
        $this->checkPermission($conversation, 'delete');
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
     * @return array<int, array{role: string, content: string, parts: array, metadata: array}>
     */
    public function getHistoryArray(SynapseConversation $conversation): array
    {
        $history = [];
        $messages = $this->getMessages($conversation);

        foreach ($messages as $msg) {
            if (!$msg->isDisplayable()) {
                continue;
            }
            $role     = $msg->getRole()->value;
            $content  = $msg->getDecryptedContent() ?? $msg->getContent();
            $metadata = $msg->getMetadata() ?? [];
            $parts    = [['text' => $content]];

            $history[] = [
                'role'     => $role,
                'content'  => $content,
                'parts'    => $parts,
                'metadata' => $metadata,
            ];
        }

        return $history;
    }

    /**
     * Définit la conversation courante (contexte thread-local)
     *
     * @param SynapseConversation|null $conversation SynapseConversation courante
     */
    public function setCurrentConversation(?SynapseConversation $conversation): void
    {
        $this->currentConversation = $conversation;
    }

    /**
     * Récupère la conversation courante
     *
     * @return SynapseConversation|null SynapseConversation courante ou null
     */
    public function getCurrentConversation(): ?SynapseConversation
    {
        return $this->currentConversation;
    }

    // Méthodes privées

    /**
     * Définit le titre d'une conversation avec chiffrement transparent
     */
    private function setTitle(SynapseConversation $conversation, string $title): void
    {
        if ($this->encryptionService !== null) {
            $title = $this->encryptionService->encrypt($title);
        }
        $conversation->setTitle($title);
    }

    /**
     * Définit le contenu d'un message avec chiffrement transparent
     */
    private function setMessageContent(SynapseMessage $message, string $content): void
    {
        $message->setDecryptedContent($content);

        if ($this->encryptionService !== null) {
            $content = $this->encryptionService->encrypt($content);
        }
        $message->setContent($content);
    }

    /**
     * Vérifie les permissions sur une conversation
     *
     * @throws AccessDeniedException Si pas de permission
     */
    private function checkPermission(SynapseConversation $conversation, string $action): void
    {
        if ($this->permissionChecker === null) {
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
     * Instancie une nouvelle conversation
     *
     * À override dans les projets si classe custom
     */
    protected function instantiateConversation(): SynapseConversation
    {
        $class = $this->getConversationClass();
        return new $class();
    }

    /**
     * Instancie un nouveau message
     *
     * À override dans les projets si classe custom
     */
    protected function instantiateMessage(): SynapseMessage
    {
        $class = $this->getMessageClass();
        return new $class();
    }

    /**
     * Retourne la classe SynapseConversation à utiliser
     *
     * À override dans les projets
     */
    protected function getConversationClass(): string
    {
        return $this->conversationClass ?? SynapseConversation::class;
    }

    /**
     * Retourne la classe SynapseMessage à utiliser
     *
     * À override dans les projets
     */
    protected function getMessageClass(): string
    {
        return $this->messageClass ?? SynapseMessage::class;
    }
}
