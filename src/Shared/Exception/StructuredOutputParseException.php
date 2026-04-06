<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Levée lorsque la réponse textuelle finale d'un LLM, attendue en JSON via
 * `response_format`, ne peut pas être décodée (JSON invalide, tronqué, etc.).
 *
 * Le texte brut est conservé pour faciliter le debug côté appelant (log,
 * affichage admin, etc.).
 */
final class StructuredOutputParseException extends LlmException
{
    public function __construct(
        string $message,
        private readonly string $rawText,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getRawText(): string
    {
        return $this->rawText;
    }
}
