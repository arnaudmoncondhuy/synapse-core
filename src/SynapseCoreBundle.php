<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore;

use ArnaudMoncondhuy\SynapseCore\Infrastructure\DependencyInjection\SynapseCoreExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Classe principale du Bundle SynapseCore.
 *
 * Point d'entrée pour l'intégration dans le kernel Symfony.
 * Charge la couche métier : orchestration IA, clients LLM, stockage, et services.
 */
class SynapseCoreBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SynapseCoreExtension();
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
