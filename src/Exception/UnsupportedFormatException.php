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

namespace UtafitiLabs\ImageBundle\Exception;

final class UnsupportedFormatException extends \RuntimeException implements ImageExceptionInterface
{
    public function __construct(string $driver, string $format)
    {
        parent::__construct(\sprintf('The %s driver cannot write %s on this server: its library was built without it. Choose another format, or the other driver.', $driver, $format));
    }
}
