<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Levée lorsque l'option `response_format` passée à {@see \ArnaudMoncondhuy\SynapseCore\Engine\ChatService::ask()}
 * est malformée (ex : `type` absent, `json_schema.schema` manquant, etc.).
 *
 * Il s'agit d'une erreur de l'appelant (programmation), d'où l'héritage de
 * {@see \InvalidArgumentException}.
 */
final class InvalidResponseFormatException extends \InvalidArgumentException implements SynapseException
{
}
