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
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use UtafitiLabs\ImageBundle\Event\RenditionsFailed;
use UtafitiLabs\ImageBundle\Event\RenditionsWritten;
use UtafitiLabs\ImageBundle\Message\MakeRenditions;
use UtafitiLabs\ImageBundle\UtafitiLabsImageBundle;

/**
 * FrameworkBundle with Messenger (MakeRenditions routed to an in-memory transport), flysystem-bundle with memory
 * storages, TwigBundle and the bundle, configured with four profiles of an imagined application.
 */
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
            'messenger' => [
                'transports' => ['async' => 'in-memory://'],
                'routing' => [MakeRenditions::class => 'async'],
            ],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('flysystem', ['storages' => [
            'app.storage.originals' => ['adapter' => 'memory'],
            'app.storage.renditions' => ['adapter' => 'memory', 'public_url' => 'https://media.example.com'],
        ]]);
        $container->extension('utafitilabs_image', [
            'storages' => ['originals' => 'app.storage.originals', 'renditions' => 'app.storage.renditions'],
            'profiles' => [
                'portrait' => [
                    'folder' => 'people',
                    'fit' => 'cover',
                    'aspect' => '4:5',
                    'sizes' => [320, 800],
                    'format' => 'jpeg',
                    'background' => '#FFFFFF',
                ],
                'cutout' => [
                    'folder' => 'people',
                    'accept' => ['image/png'],
                    'fit' => 'height',
                    'sizes' => [400],
                    'format' => 'png',
                ],
                'gallery' => [
                    'sizes' => [320, 800],
                    'format' => 'webp',
                    'background' => '#FFFFFF',
                    'fallback' => ['size' => 800, 'format' => 'jpeg'],
                    'generate' => 'async',
                ],
            ],
        ]);

        $services = $container->services();
        $services->set('logger', NullLogger::class);
        $services->set('app.people_names', PeopleNames::class)
            ->tag('utafitilabs_image.names', ['profile' => 'portrait'])
            ->tag('utafitilabs_image.names', ['profile' => 'cutout']);
        $services->set('app.recorded_events', RecordedEvents::class)
            ->public()
            ->tag('kernel.event_listener', ['event' => RenditionsWritten::class, 'method' => 'record'])
            ->tag('kernel.event_listener', ['event' => RenditionsFailed::class, 'method' => 'record']);
        $services->set('app.image_services_consumer', ImageServicesConsumer::class)->autowire()->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
    }
}
