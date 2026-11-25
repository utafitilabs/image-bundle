<?php

declare(strict_types=1);

/*
 * This file is part of the UtafitiLabs Image Bundle.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace UtafitiLabs\ImageBundle\Tests\Functional;

use League\FlysystemBundle\FlysystemBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use UtafitiLabs\ImageBundle\UtafitiLabsImageBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new FlysystemBundle(),
            new TwigBundle(),
            new UtafitiLabsImageBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__, 2);
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/utafitilabs_image/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/utafitilabs_image/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'test' => true,
            'php_errors' => ['log' => true],
        ]);
        $container->extension('flysystem', ['storages' => [
            'app.storage.originals' => ['adapter' => 'memory'],
            'app.storage.renditions' => ['adapter' => 'memory', 'public_url' => 'https://media.example.com'],
        ]]);
        $container->extension('utafitilabs_image', [
            'storages' => ['originals' => 'app.storage.originals', 'renditions' => 'app.storage.renditions'],
            'profiles' => [
                'thumb' => ['sizes' => [320, 800], 'format' => 'webp'],
            ],
        ]);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
