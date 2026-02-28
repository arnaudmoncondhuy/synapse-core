<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\DataFixtures;

use ArnaudMoncondhuy\SynapseCore\Storage\Entity\SynapsePreset;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class SynapsePresetFixture extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $presets = [
            [
                'name' => 'Gemini Flash (rapide)',
                'provider' => 'gemini',
                'model' => 'gemini-3-flash',
                'temperature' => 1.0,
                'topP' => 0.95,
                'topK' => 40,
            ],
            [
                'name' => 'Gemini Pro (puissant)',
                'provider' => 'gemini',
                'model' => 'gemini-3.1-pro',
                'temperature' => 0.9,
                'topP' => 0.95,
                'topK' => 40,
            ],
            [
                'name' => 'OVH GPT OSS (open-source)',
                'provider' => 'ovh',
                'model' => 'gpt-oss-120b',
                'temperature' => 0.8,
                'topP' => 0.95,
                'topK' => 40,
            ],
        ];

        foreach ($presets as $data) {
            // Idempotent : vérifie l'existence par nom
            if ($manager->getRepository(SynapsePreset::class)->findOneBy(['name' => $data['name']]) !== null) {
                continue;
            }

            $preset = new SynapsePreset();
            $preset->setName($data['name']);
            $preset->setProviderName($data['provider']);
            $preset->setModel($data['model']);
            $preset->setGenerationTemperature($data['temperature']);
            $preset->setGenerationTopP($data['topP']);
            $preset->setGenerationTopK($data['topK']);
            $preset->setStreamingEnabled(true);

            $manager->persist($preset);
        }

        $manager->flush();
    }
}
