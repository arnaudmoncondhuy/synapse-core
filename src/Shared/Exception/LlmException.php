<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Exception de base pour les erreurs liées aux clients LLM.
 */
class LlmException extends \RuntimeException implements SynapseException {}
