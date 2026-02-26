<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Levée en cas de dépassement des limites de débit de l'API (429).
 */
class LlmRateLimitException extends LlmException {}
