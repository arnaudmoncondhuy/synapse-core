<?php

declare(strict_types=1);

namespace ArnaudMoncondhuy\SynapseCore\Tests;

use Symfony\Bundle\DoctrineBundle\DoctrineBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

class TestKernel extends Kernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
        ];
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-secret',
                'test' => true,
            ]);

            $container->loadFromExtension('doctrine', [
                'dbal' => [
                    'driver' => 'pdo_sqlite',
                    'path' => ':memory:',
                ],
                'orm' => [
                    'auto_mapping' => false,
                    'mappings' => [
                        'SynapseCore' => [
                            'type' => 'attribute',
                            'is_bundle' => false,
                            'dir' => \dirname(__DIR__).'/../../src/Storage/Entity',
                            'prefix' => 'ArnaudMoncondhuy\SynapseCore\Storage\Entity',
                        ],
                    ],
                ],
            ]);
        });
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 5);
    }
}
