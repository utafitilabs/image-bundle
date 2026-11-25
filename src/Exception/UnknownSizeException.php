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

final class UnknownSizeException extends \InvalidArgumentException implements ImageExceptionInterface
{
    /**
     * @param list<int> $sizes
     */
    public static function size(string $profile, int $size, array $sizes): self
    {
        return new self(\sprintf('The image profile "%s" has no size %d; its sizes are: %s.', $profile, $size, implode(', ', $sizes)));
    }

    public static function noFallback(string $profile): self
    {
        return new self(\sprintf('The image profile "%s" has no fallback.', $profile));
    }

    public static function notWidths(string $profile): self
    {
        return new self(\sprintf('The sizes of the image profile "%s" are not widths, so it has no srcset; a srcset needs "fit: width" or "fit: cover".', $profile));
    }
}
