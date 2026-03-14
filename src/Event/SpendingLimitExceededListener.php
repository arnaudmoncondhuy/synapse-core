<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Event;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseSpendingLimitLog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Persiste chaque dépassement de plafond dans {@see SynapseSpendingLimitLog}.
 *
 * Enregistré automatiquement via l'attribut #[AsEventListener].
 * Ce listener est déclenché par {@see SpendingLimitChecker::assertCanSpend()}
 * avant que l'exception LlmQuotaException ne soit levée.
 */
#[AsEventListener(event: SynapseSpendingLimitExceededEvent::class)]
final class SpendingLimitExceededListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(SynapseSpendingLimitExceededEvent $event): void
    {
        $log = new SynapseSpendingLimitLog(
            userId: $event->getUserId(),
            scope: $event->getScope(),
            scopeId: $event->getScopeId(),
            period: $event->getPeriod(),
            limitAmount: $event->getLimitAmount(),
            consumption: $event->getConsumption(),
            estimatedCost: $event->getEstimatedCost(),
            overrunAmount: $event->getOverrunAmount(),
            currency: $event->getCurrency(),
        );

        $this->em->persist($log);
        $this->em->flush();
    }
}
