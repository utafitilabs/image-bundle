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

namespace UtafitiLabs\ImageBundle\Model;

/**
 * One rendition a profile makes: its size, the path pattern of its name, its encoding (null keeps the original's) and
 * how it is encoded.
 */
final readonly class RenditionSpec
{
    public function __construct(
        public int $size,
        public string $path,
        public ?FormatEnum $format,
        public ?int $quality,
        public bool $interlace,
        public bool $fallback = false,
    ) {
    }

    /** The format this rendition is written in for an original of the given format. */
    public function formatFor(FormatEnum $original): FormatEnum
    {
        return $this->format ?? $original;
    }
}
