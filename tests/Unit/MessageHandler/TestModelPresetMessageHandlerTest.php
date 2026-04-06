<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests\Unit\MessageHandler;

use ArnaudMoncondhuy\SynapseCore\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Message\TestPresetMessage;
use ArnaudMoncondhuy\SynapseCore\MessageHandler\TestModelPresetMessageHandler;
use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapseModelPreset;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapseModelPresetRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class TestModelPresetMessageHandlerTest extends TestCase
{
    private PresetValidatorAgent $agent;
    private SynapseModelPresetRepository $presetRepository;
    private CacheInterface $cache;
    private TestModelPresetMessageHandler $handler;

    protected function setUp(): void
    {
        $this->agent = $this->createMock(PresetValidatorAgent::class);
        $this->presetRepository = $this->createMock(SynapseModelPresetRepository::class);
        $this->cache = $this->createMock(CacheInterface::class);

        $this->handler = new TestModelPresetMessageHandler(
            $this->agent,
            $this->presetRepository,
            $this->cache,
        );
    }

    public function testInvokeWithNonExistentPresetDeletesCache(): void
    {
        $this->presetRepository->method('find')->with(42)->willReturn(null);
        $this->cache->expects($this->once())
            ->method('delete')
            ->with('synapse_model_preset_test_42');

        ($this->handler)(new TestPresetMessage(42));
    }

    public function testInvokeWithExistingPresetRunsAllThreeSteps(): void
    {
        $preset = $this->createStub(SynapseModelPreset::class);
        $this->presetRepository->method('find')->with(1)->willReturn($preset);

        // Cache::get is called multiple times (lock + data reads + writes)
        $this->cache->method('get')->willReturnCallback(function (string $key, callable $callback) {
            $item = $this->createStub(ItemInterface::class);

            return $callback($item);
        });
        $this->cache->method('delete')->willReturn(true);

        $this->agent->expects($this->exactly(3))
            ->method('runStep');

        ($this->handler)(new TestPresetMessage(1));
    }
}
