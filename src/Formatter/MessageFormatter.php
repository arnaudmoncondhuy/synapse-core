<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Formatter;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\MessageFormatterInterface;
use ArnaudMoncondhuy\SynapseCore\Service\AttachmentStorageService;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Formateur de messages pour le format OpenAI canonical.
 *
 * Convertit entre le format des entités Doctrine et le format OpenAI canonical.
 */
#[AsAlias(id: MessageFormatterInterface::class)]
class MessageFormatter implements MessageFormatterInterface
{
    /** @var SynapseMessageAttachment[] Pièces jointes générées non consommées (dernier assistant [image] sans user suivant) */
    private array $trailingGeneratedAttachments = [];

    public function __construct(
        private EncryptionServiceInterface $encryptionService,
        private ?AttachmentStorageService $attachmentStorage = null,
        private ?EntityManagerInterface $em = null,
    ) {
    }

    /**
     * Convertit les entités SynapseMessage vers le format OpenAI canonical.
     *
     * @param iterable<object> $entities
     *
     * @return array<int, array<string, mixed>>
     */
    public function entitiesToApiFormat(iterable $entities): array
    {
        $messages = [];
        /** @var SynapseMessageAttachment[] $pendingGeneratedAttachments */
        $pendingGeneratedAttachments = [];

        foreach ($entities as $entity) {
            // Handle serialized entities (Doctrine converts to arrays in closure context)
            if (is_array($entity)) {
                if (isset($entity['role']) && (isset($entity['content']) || isset($entity['parts']))) {
                    $decrypted = $entity;
                    if (!empty($decrypted['content']) && is_string($decrypted['content']) && $this->encryptionService->isEncrypted($decrypted['content'])) {
                        $decrypted['content'] = $this->encryptionService->decrypt($decrypted['content']);
                    }
                    if (isset($decrypted['parts'][0]['text']) && is_string($decrypted['parts'][0]['text']) && $this->encryptionService->isEncrypted($decrypted['parts'][0]['text'])) {
                        $decrypted['parts'][0]['text'] = $this->encryptionService->decrypt($decrypted['parts'][0]['text']);
                    }
                    $messages[] = $decrypted;
                    continue;
                }
            }

            if (!$entity instanceof SynapseMessage) {
                continue;
            }

            $role = $entity->getRole();
            $content = $entity->getDecryptedContent();
            $mappedRole = $this->mapRoleToOpenAi($role);

            // Assistant attachment-only message: collect generated attachment UUIDs for next user message
            if ('[image]' === $content && 'assistant' === $mappedRole && null !== $this->em) {
                $attachmentEntities = $this->em->getRepository(SynapseMessageAttachment::class)->findBy(['messageId' => $entity->getId()]);
                foreach ($attachmentEntities as $att) {
                    $pendingGeneratedAttachments[] = $att;
                }
                $messages[] = ['role' => $mappedRole, 'content' => '[Image générée]'];
                continue;
            }

            // User message: prepend any pending generated attachments + own attached files
            if ('user' === $mappedRole && null !== $this->em) {
                $attachmentEntities = $this->em->getRepository(SynapseMessageAttachment::class)->findBy(['messageId' => $entity->getId()]);
                $allAttachments = array_merge($pendingGeneratedAttachments, $attachmentEntities);
                $pendingGeneratedAttachments = [];

                if (!empty($allAttachments)) {
                    $attachmentParts = $this->loadAttachmentParts($allAttachments);
                    if (!empty($attachmentParts)) {
                        $parts = [];
                        if (!empty($content)) {
                            $parts[] = ['type' => 'text', 'text' => $content];
                        }
                        $messages[] = ['role' => $mappedRole, 'content' => array_merge($parts, $attachmentParts)];
                        continue;
                    }
                }
            } else {
                // Non-user message: discard any pending attachments (conversation branched)
                $pendingGeneratedAttachments = [];
            }

            $messages[] = [
                'role' => $mappedRole,
                'content' => $content,
            ];
        }

        // Pièces jointes non consommées = dernier message assistant était [image] sans user suivant dans l'historique
        $this->trailingGeneratedAttachments = $pendingGeneratedAttachments;

        return $messages;
    }

    /**
     * Retourne les pièces jointes générées du dernier message [image] non injectées dans un user suivant.
     * À appeler après entitiesToApiFormat() pour les injecter dans le message courant.
     *
     * @return list<array{type: string, image_url?: array{url: string}, text?: string}>
     */
    public function getAndClearTrailingAttachments(): array
    {
        $images = $this->loadAttachmentParts($this->trailingGeneratedAttachments);
        $this->trailingGeneratedAttachments = [];

        return $images;
    }

    /**
     * Map internal MessageRole enum to OpenAI role strings.
     */
    private function mapRoleToOpenAi(MessageRole $role): string
    {
        return match ($role) {
            MessageRole::USER => 'user',
            MessageRole::MODEL => 'assistant',
            MessageRole::FUNCTION => 'tool',
            MessageRole::SYSTEM => 'system',
        };
    }

    /**
     * Convertit le format OpenAI canonical vers des entités SynapseMessage.
     *
     * Utile pour l'import de conversations ou les tests.
     * Les entités retournées ne sont PAS persistées.
     */
    public function apiFormatToEntities(array $messages, SynapseConversation $conversation): array
    {
        $entities = [];

        foreach ($messages as $msg) {
            if (!isset($msg['role']) || !isset($msg['content'])) {
                continue;
            }

            // Déterminer la classe SynapseMessage concrète depuis la conversation
            $messageClass = null;
            $firstMessage = $conversation->getMessages()->first();
            if ($firstMessage) {
                $messageClass = get_class($firstMessage);
            }

            if (!$messageClass) {
                // Si aucune classe n'est trouvée, on ne peut pas créer d'entité générique
                // PHPStan signale que SynapseMessage est abstrait.
                continue;
            }

            $entity = new $messageClass();
            $entity->setConversation($conversation);
            $role = is_string($msg['role'] ?? null) ? (string) $msg['role'] : 'user';
            $entity->setRole($this->mapRoleFromOpenAi($role));
            $content = is_string($msg['content'] ?? null) ? (string) $msg['content'] : '';
            $entity->setContent($content);

            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Load attachment data from entities and return as OpenAI multipart content parts.
     *
     * Uses image_url type with data URIs for all file types — the MIME type
     * is embedded in the data URI and extracted by each LLM client.
     *
     * @param SynapseMessageAttachment[] $attachments
     *
     * @return list<array{type: string, image_url?: array{url: string}, text?: string}>
     */
    private function loadAttachmentParts(array $attachments): array
    {
        $parts = [];
        foreach ($attachments as $att) {
            if (null === $this->attachmentStorage) {
                continue;
            }
            $path = $this->attachmentStorage->getAbsolutePath($att);
            if (!file_exists($path)) {
                continue;
            }
            $mime = $att->getMimeType();
            if (str_starts_with($mime, 'text/') || 'application/json' === $mime) {
                $textContent = (string) file_get_contents($path);
                if (\strlen($textContent) > 102400) {
                    $textContent = substr($textContent, 0, 102400)."\n...[tronqué]";
                }
                $parts[] = [
                    'type' => 'text',
                    'text' => "--- Fichier : {$att->getDisplayName()} ---\n{$textContent}\n--- Fin fichier ---",
                ];
            } else {
                $parts[] = [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path))],
                ];
            }
        }

        return $parts;
    }

    /**
     * Map OpenAI role strings to internal MessageRole enum.
     */
    private function mapRoleFromOpenAi(string $role): MessageRole
    {
        return match ($role) {
            'user' => MessageRole::USER,
            'assistant' => MessageRole::MODEL,
            'tool' => MessageRole::FUNCTION,
            'system' => MessageRole::SYSTEM,
            default => MessageRole::USER,
        };
    }
}
