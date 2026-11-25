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
 * What the application keeps on its record for a stored original: its name in the profile's folder, its type and
 * size, its dimensions as it is shown (after the EXIF orientation; null for an SVG that states none), its SHA-256 and
 * when the camera took it.
 */
final readonly class StoredImage
{
    public function __construct(
        public string $name,
        public string $mimeType,
        public int $bytes,
        public ?int $width,
        public ?int $height,
        public string $sha256,
        public ?\DateTimeImmutable $takenAt,
    ) {
    }
}
