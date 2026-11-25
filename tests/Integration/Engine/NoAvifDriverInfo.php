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

namespace UtafitiLabs\ImageBundle\Tests\Integration\Engine;

use Imagine\Driver\Info;
use Imagine\Image\Palette\PaletteInterface;

/**
 * A driver whose library was built without AVIF.
 */
final readonly class NoAvifDriverInfo implements Info
{
    public function __construct(private Info $info)
    {
    }

    public static function get($required = true): void
    {
        throw new \LogicException('Make it with its constructor.');
    }

    public function checkVersionIsSupported(): void
    {
        $this->info->checkVersionIsSupported();
    }

    public function getDriverVersion($raw = false): string
    {
        $version = $this->info->getDriverVersion($raw);

        return \is_string($version) ? $version : '';
    }

    public function getEngineVersion($raw = false): string
    {
        $version = $this->info->getEngineVersion($raw);

        return \is_string($version) ? $version : '';
    }

    public function hasFeature($features)
    {
        return $this->info->hasFeature($features);
    }

    public function requireFeature($features): void
    {
        $this->info->requireFeature($features);
    }

    public function getSupportedFormats()
    {
        return $this->info->getSupportedFormats();
    }

    public function isFormatSupported($format)
    {
        return 'avif' !== $format && $this->info->isFormatSupported($format);
    }

    public function requirePaletteSupport(PaletteInterface $palette): void
    {
        $this->info->requirePaletteSupport($palette);
    }

    public function isPaletteSupported(PaletteInterface $palette)
    {
        return $this->info->isPaletteSupported($palette);
    }
}
