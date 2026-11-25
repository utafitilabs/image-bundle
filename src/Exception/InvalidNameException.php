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

final class InvalidNameException extends \InvalidArgumentException implements ImageExceptionInterface
{
    public static function name(string $name): self
    {
        return new self(\sprintf('"%s" is not a stored image name: letters, digits, "_", "-" and ".", starting with a letter or a digit, with an extension.', $name));
    }

    public static function label(string $label): self
    {
        return new self(\sprintf('"%s" is not a label: letters, digits, "_" and "-", starting with a letter or a digit.', $label));
    }
}
