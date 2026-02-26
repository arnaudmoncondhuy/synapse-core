<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Core\Formatter;

use ArnaudMoncondhuy\SynapseCore\Contract\EncryptionServiceInterface;
use ArnaudMoncondhuy\SynapseCore\Contract\MessageFormatterInterface;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseConversation;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessage;
use ArnaudMoncondhuy\SynapseCore\Shared\Enum\MessageRole;

/**
 * Formateur de messages pour le format OpenAI canonical
 *
 * Convertit entre le format des entités Doctrine et le format OpenAI canonical.
 */
class MessageFormatter implements MessageFormatterInterface
{
    public function __construct(
        private ?EncryptionServiceInterface $encryptionService = null,
    ) {}
    /**
     * Convertit les entités SynapseMessage vers le format OpenAI canonical
     *
     * Format OpenAI:
     * [
     *   { "role": "user", "content": "Hello" },
     *   { "role": "assistant", "content": "Hi!", "tool_calls": [...] },
     *   { "role": "tool", "tool_call_id": "...", "content": "..." }
     * ]
     */
    public function entitiesToApiFormat(array $messageEntities): array
    {
        $messages = [];

        foreach ($messageEntities as $entity) {
            // Handle serialized entities (Doctrine converts to arrays in closure context)
            if (is_array($entity)) {
                // If it looks like already-formatted message data, try to reconstruct
                if (isset($entity['role']) && (isset($entity['content']) || isset($entity['parts']))) {
                    // Decrypt content if needed
                    $decrypted = $entity;
                    if ($this->encryptionService !== null) {
                        if (!empty($decrypted['content']) && is_string($decrypted['content']) && $this->encryptionService->isEncrypted($decrypted['content'])) {
                            $decrypted['content'] = $this->encryptionService->decrypt($decrypted['content']);
                        }
                        if (isset($decrypted['parts'][0]['text']) && is_string($decrypted['parts'][0]['text']) && $this->encryptionService->isEncrypted($decrypted['parts'][0]['text'])) {
                            $decrypted['parts'][0]['text'] = $this->encryptionService->decrypt($decrypted['parts'][0]['text']);
                        }
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

            // Map internal roles to OpenAI roles
            $mappedRole = $this->mapRoleToOpenAi($role);

            $messages[] = [
                'role'    => $mappedRole,
                'content' => $content,
            ];
        }

        return $messages;
    }

    /**
     * Map internal MessageRole enum to OpenAI role strings
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
     * Convertit le format OpenAI canonical vers des entités SynapseMessage
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
            $messageClass = get_class($conversation->getMessages()->first() ?: new SynapseMessage());

            $entity = new $messageClass();
            $entity->setConversation($conversation);
            $entity->setRole($this->mapRoleFromOpenAi($msg['role']));
            $entity->setContent($msg['content'] ?? '');

            $entities[] = $entity;
        }

        return $entities;
    }

    /**
     * Map OpenAI role strings to internal MessageRole enum
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
