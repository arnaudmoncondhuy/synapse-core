<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Shared\Exception;

/**
 * Levée quand une des méthodes `activate()` d'un repo refuse l'activation
 * parce que l'entité ne passe pas sa validation de ligne (preset invalide,
 * modèle désactivé, etc.).
 *
 * Chaque repo (Preset, Agent, Workflow) encapsule sa propre logique de
 * validation ; les controllers n'ont qu'à attraper cette exception pour
 * afficher un flash d'erreur au lieu de dupliquer le check.
 */
final class CannotActivateException extends \RuntimeException
{
    public function __construct(
        public readonly string $entityLabel,
        public readonly string $reason,
    ) {
        parent::__construct(sprintf(
            'Impossible d\'activer « %s » : %s',
            $entityLabel,
            $reason,
        ));
    }
}
