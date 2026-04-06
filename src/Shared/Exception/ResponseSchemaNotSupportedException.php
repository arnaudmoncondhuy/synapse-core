<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Levée lorsqu'un appel à {@see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService::ask()}
 * demande un `response_format` sur un modèle dont la capability
 * `supports_response_schema` est à `false`.
 *
 * Fail fast : l'exception est jetée avant l'appel au client LLM, pour éviter
 * un échec côté provider avec un message obscur.
 */
final class ResponseSchemaNotSupportedException extends LlmException
{
    /**
     * @param list<string> $supportedModels modèles connus comme supportant les structured outputs,
     *                                      affichés dans le message pour aider l'appelant
     */
    public static function forModel(string $model, array $supportedModels = []): self
    {
        $message = \sprintf(
            'Le modèle "%s" ne supporte pas les structured outputs (response_format).',
            $model,
        );

        if ([] !== $supportedModels) {
            $message .= \sprintf(
                ' Modèles compatibles : %s.',
                implode(', ', $supportedModels),
            );
        }

        return new self($message);
    }
}
