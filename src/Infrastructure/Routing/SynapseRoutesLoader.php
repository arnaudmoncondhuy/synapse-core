<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Infrastructure\Routing;

use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader centralisé pour les bundles Synapse.
 *
 * Permet à l'utilisateur de charger toutes les routes Synapse
 * en une seule ligne dans config/routes.yaml :
 *
 *   _synapse:
 *       resource: .
 *       type: synapse
 *
 * Le loader détecte automatiquement quels bundles sont installés
 * (Admin, Chat) et charge leurs routes en conséquence.
 *
 * Les routes Admin sont exposées sous /synapse/admin*.
 * Les routes Chat UI sont sous /synapse/chat et les API sous /synapse/api/*.
 *
 * Pour ajouter un préfixe global (ex: /myapp), utiliser l'option `prefix`
 * dans routes.yaml :
 *   _synapse:
 *       resource: .
 *       type: synapse
 *       prefix: /myapp
 */
class SynapseRoutesLoader extends Loader
{
    private bool $isLoaded = false;

    public function __construct(private readonly KernelInterface $kernel)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->isLoaded) {
            throw new \RuntimeException('Do not add the "synapse" route loader twice.');
        }

        $collection = new RouteCollection();
        $bundles = $this->kernel->getBundles();

        // Routes Admin
        if (isset($bundles['SynapseAdminBundle'])) {
            /** @var RouteCollection $adminRoutes */
            $adminRoutes = $this->import('@SynapseAdminBundle/config/routes.yaml');
            $collection->addCollection($adminRoutes);
        }

        // Routes Chat — API + UI already at /synapse/api/* and /synapse/chat
        if (isset($bundles['SynapseChatBundle'])) {
            /** @var RouteCollection $chatRoutes */
            $chatRoutes = $this->import('@SynapseChatBundle/config/routes.yaml');
            $collection->addCollection($chatRoutes);
        }

        $this->isLoaded = true;

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'synapse' === $type;
    }
}
