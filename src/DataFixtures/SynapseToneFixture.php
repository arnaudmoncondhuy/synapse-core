<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\DataFixtures;

use ArnaudMoncondhuy\SynapseCore\Service\ToneInitializer;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Charge les 20 tons de réponse par défaut du bundle.
 *
 * Ces tones sont marqués isBuiltin = true pour les identifier comme tones par défaut.
 * Ils peuvent être supprimés depuis l'interface d'administration et restaurés via le bouton dédié.
 *
 * Usage : php bin/console doctrine:fixtures:load --append
 */
class SynapseToneFixture extends Fixture
{
    public function __construct(
        private ToneInitializer $toneInitializer,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $this->toneInitializer->initialize($manager);
    }
}
