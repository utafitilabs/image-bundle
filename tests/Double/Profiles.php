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

namespace UtafitiLabs\ImageBundle\Tests\Double;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use UtafitiLabs\ImageBundle\Model\Profile;
use UtafitiLabs\ImageBundle\UtafitiLabsImageBundle;

/**
 * Profiles made the way an application makes them: through the bundle's configuration tree, defaults included.
 */
final class Profiles
{
    /**
     * @param array<string, mixed> $profile
     */
    public static function make(array $profile, string $name = 'thumb'): Profile
    {
        return Profile::fromConfig($name, self::processed([$name => $profile])[$name]);
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return array<string, array<string, mixed>>
     */
    public static function processed(array $profiles): array
    {
        $extension = (new UtafitiLabsImageBundle())->getContainerExtension();
        if (!$extension instanceof ConfigurationExtensionInterface) {
            throw new \LogicException('The bundle has no configuration.');
        }
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        if (!$configuration instanceof ConfigurationInterface) {
            throw new \LogicException('The bundle has no configuration.');
        }
        /** @var array{profiles: array<string, array<string, mixed>>} $config */
        $config = (new Processor())->processConfiguration($configuration, [[
            'storages' => ['originals' => 'app.storage.originals', 'renditions' => 'app.storage.renditions'],
            'profiles' => $profiles,
        ]]);
        $processed = [];
        foreach ($config['profiles'] as $key => $value) {
            $value['originals'] ??= 'app.storage.originals';
            $value['renditions'] ??= 'app.storage.renditions';
            $processed[$key] = $value;
        }

        return $processed;
    }
}
