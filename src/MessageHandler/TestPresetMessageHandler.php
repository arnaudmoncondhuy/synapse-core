<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\MessageHandler;

use ArnaudMoncondhuy\SynapseCore\Core\Agent\PresetValidator\PresetValidatorAgent;
use ArnaudMoncondhuy\SynapseCore\Message\TestPresetMessage;
use ArnaudMoncondhuy\SynapseCore\Storage\Repository\SynapsePresetRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[AsMessageHandler]
class TestPresetMessageHandler
{
    public function __construct(
        private PresetValidatorAgent $agent,
        private SynapsePresetRepository $presetRepository,
        private CacheInterface $cache,
    ) {}

    public function __invoke(TestPresetMessage $message): void
    {
        $id = $message->getPresetId();
        $cacheKey = sprintf('synapse_preset_test_%d', $id);
        $lockKey = sprintf('synapse_test_lock_%d', $id);

        $preset = $this->presetRepository->find($id);
        if (!$preset) {
            $this->cache->delete($cacheKey);
            return;
        }

        // --- PROGRESSIVE EXECUTION (Consistent with Polling) ---
        for ($step = 1; $step <= 3; $step++) {
            // Acquisition du verrou
            $this->cache->get($lockKey, function (ItemInterface $item) {
                $item->expiresAfter(60);
                return true;
            });

            try {
                $data = $this->cache->get($cacheKey, fn() => null);

                // Skip if step already completed (by this handler previously or by polling)
                if ($data) {
                    if ($step === 1 && ($data['progress'] ?? 0) >= 33) continue;
                    if ($step === 2 && ($data['progress'] ?? 0) >= 66) continue;
                    if ($step === 3 && ($data['progress'] ?? 0) >= 100) continue;
                }

                $report = $data['report'] ?? [];
                $this->agent->runStep($step, $preset, $report);

                $newData = [
                    'status' => ($step === 3) ? 'completed' : 'processing',
                    'progress' => match ($step) {
                        1 => 33,
                        2 => 66,
                        3 => 100,
                    },
                    'report' => $report,
                ];

                $this->cache->delete($cacheKey);
                $this->cache->get($cacheKey, function (ItemInterface $item) use ($newData) {
                    $item->expiresAfter(3600);
                    return $newData;
                });
            } finally {
                $this->cache->delete($lockKey);
            }
        }
    }
}
