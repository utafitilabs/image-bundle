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
use Imagine\Gd\DriverInfo;
use Imagine\Gd\Imagine;
use Imagine\Image\ImagineInterface;

final class GdRenditionEngineTest extends AbstractRenditionEngineTestCase
{
    protected static function driver(): string
    {
        return 'gd';
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
