<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Timing;

use ArnaudMoncondhuy\SynapseCore\Timing\AppTimingSubscriber;
use ArnaudMoncondhuy\SynapseCore\Timing\SynapseProfiler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

class AppTimingSubscriberTest extends TestCase
{
    public function testGetSubscribedEventsReturnsCorrectEvents(): void
    {
        $events = AppTimingSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(KernelEvents::REQUEST, $events);
        $this->assertArrayHasKey(KernelEvents::RESPONSE, $events);
        $this->assertSame('onKernelRequest', $events[KernelEvents::REQUEST][0]);
        $this->assertSame('onKernelResponse', $events[KernelEvents::RESPONSE][0]);
    }

    public function testOnKernelRequestStartsProfilerForMainRequest(): void
    {
        $profiler = $this->createMock(SynapseProfiler::class);
        $profiler->expects($this->once())
            ->method('start')
            ->with('app', 'total', $this->isString());

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber = new AppTimingSubscriber($profiler);
        $subscriber->onKernelRequest($event);
    }

    public function testOnKernelRequestIgnoresSubRequests(): void
    {
        $profiler = $this->createMock(SynapseProfiler::class);
        $profiler->expects($this->never())->method('start');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        $subscriber = new AppTimingSubscriber($profiler);
        $subscriber->onKernelRequest($event);
    }

    public function testOnKernelResponseStopsProfilerForMainRequest(): void
    {
        $profiler = $this->createMock(SynapseProfiler::class);
        $profiler->expects($this->once())
            ->method('stop')
            ->with('app', 'total');

        $kernel = $this->createStub(HttpKernelInterface::class);
        $request = new Request();
        $response = new Response();
        $event = new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response);

        $subscriber = new AppTimingSubscriber($profiler);
        $subscriber->onKernelResponse($event);
    }
}
