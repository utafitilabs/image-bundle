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
use Imagine\Image\ImagineInterface;
use Imagine\Imagick\DriverInfo;
use Imagine\Imagick\Imagine;

final class ImagickRenditionEngineTest extends AbstractRenditionEngineTestCase
{
    protected function setUp(): void
    {
        if (!\extension_loaded('imagick')) {
            self::markTestSkipped('ext-imagick is not loaded.');
        }
    }

    protected static function driver(): string
    {
        return 'imagick';
    }

    protected function imagine(): ImagineInterface
    {
        return new Imagine();
    }

    protected function driverInfo(): Info
    {
        return DriverInfo::get() ?? throw new \LogicException('No driver.');
    }
}
