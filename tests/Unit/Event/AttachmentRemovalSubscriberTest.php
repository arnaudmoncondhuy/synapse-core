<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\Event;

use ArnaudMoncondhuy\SynapseCore\Event\AttachmentRemovalSubscriber;
use ArnaudMoncondhuy\SynapseCore\Service\AttachmentStorageService;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseMessageAttachment;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\TestCase;

class AttachmentRemovalSubscriberTest extends TestCase
{
    public function testPreRemoveDeletesAttachment(): void
    {
        $attachment = $this->createStub(SynapseMessageAttachment::class);

        $storageService = $this->createMock(AttachmentStorageService::class);
        $storageService->expects($this->once())
            ->method('delete')
            ->with($attachment);

        $args = $this->createStub(LifecycleEventArgs::class);
        $args->method('getObject')->willReturn($attachment);

        $subscriber = new AttachmentRemovalSubscriber($storageService);
        $subscriber->preRemove($args);
    }

    public function testPreRemoveIgnoresNonAttachmentEntities(): void
    {
        $entity = new \stdClass();

        $storageService = $this->createMock(AttachmentStorageService::class);
        $storageService->expects($this->never())->method('delete');

        $args = $this->createStub(LifecycleEventArgs::class);
        $args->method('getObject')->willReturn($entity);

        $subscriber = new AttachmentRemovalSubscriber($storageService);
        $subscriber->preRemove($args);
    }

    public function testSubscriberIsDoctrineListener(): void
    {
        $reflection = new \ReflectionClass(AttachmentRemovalSubscriber::class);
        $attributes = $reflection->getAttributes();

        $listenerAttr = null;
        foreach ($attributes as $attr) {
            if (str_contains($attr->getName(), 'AsDoctrineListener')) {
                $listenerAttr = $attr;
                break;
            }
        }

        $this->assertNotNull($listenerAttr, 'Class should have AsDoctrineListener attribute');
        $args = $listenerAttr->getArguments();
        $this->assertSame('preRemove', $args['event'] ?? $args[0] ?? null);
    }
}
