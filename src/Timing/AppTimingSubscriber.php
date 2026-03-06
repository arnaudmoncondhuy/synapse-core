<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Timing;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscriber pour mesurer le temps global d'exécution d'une requête.
 */
class AppTimingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SynapseProfiler $profiler,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 100],
            KernelEvents::RESPONSE => ['onKernelResponse', -100],
        ];
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        if (!$requestEvent->isMainRequest()) {
            return;
        }

        $this->profiler->start('app', 'total', 'Temps total de la requête');
    }

    public function onKernelResponse(ResponseEvent $responseEvent): void
    {
        if (!$responseEvent->isMainRequest()) {
            return;
        }

        $this->profiler->stop('app', 'total');
    }
}
