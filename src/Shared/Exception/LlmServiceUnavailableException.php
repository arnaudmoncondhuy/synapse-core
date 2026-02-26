<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Levée quand le service distant est indisponible (500/503).
 */
class LlmServiceUnavailableException extends LlmException {}
