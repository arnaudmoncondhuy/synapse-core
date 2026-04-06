<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\Exception;

/**
 * Levée par {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\MultiAgent} ou
 * {@see \ArnaudMoncondhuy\SynapseCore\Agent\MultiAgent\WorkflowRunner} lorsque
 * l'exécution d'un workflow ne peut pas aboutir : step inconnu, agent introuvable,
 * exception levée par un agent d'étape.
 *
 * Le `stepName` permet au caller (et à l'admin via `SynapseWorkflowRun::$errorMessage`)
 * de localiser précisément l'étape fautive dans la définition.
 */
final class WorkflowExecutionException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?string $stepName = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStepName(): ?string
    {
        return $this->stepName;
    }

    public static function stepFailed(string $stepName, string $reason, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Workflow step "%s" failed: %s', $stepName, $reason),
            $stepName,
            $previous,
        );
    }

    public static function agentNotResolvable(string $stepName, string $agentName): self
    {
        return new self(
            sprintf('Workflow step "%s" references unknown agent "%s".', $stepName, $agentName),
            $stepName,
        );
    }

    public static function invalidDefinition(string $reason): self
    {
        return new self(sprintf('Workflow definition is invalid: %s', $reason));
    }
}
