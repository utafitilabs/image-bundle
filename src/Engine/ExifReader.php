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

namespace UtafitiLabs\ImageBundle\Engine;

use Imagine\Image\Metadata\ExifMetadataReader;

/**
 * What a camera wrote into a photo that the bundle uses: its orientation and when it was taken, read with Imagine's
 * ExifMetadataReader, which names the EXIF sections' values "ifd0.Orientation" and "exif.DateTimeOriginal"
 * (vendor/imagine/imagine/src/Image/Metadata/ExifMetadataReader.php, extract()). DateTimeOriginal is
 * "YYYY:MM:DD HH:MM:SS" in the camera's own time (EXIF 2.32, 4.6.5), so it is read without a time zone.
 */
final readonly class ExifReader
{
    public function __construct(private ExifMetadataReader $reader)
    {
    }

    /** The EXIF orientation, 1 to 8; 1 when there is none or it is out of range. */
    public function orientation(string $bytes): int
    {
        $orientation = $this->reader->readData($bytes)['ifd0.Orientation'] ?? 1;

        return \is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    public function takenAt(string $bytes): ?\DateTimeImmutable
    {
        $value = $this->reader->readData($bytes)['exif.DateTimeOriginal'] ?? null;
        if (!\is_string($value)) {
            return null;
        }
        $taken = \DateTimeImmutable::createFromFormat('!Y:m:d H:i:s', trim($value));
        $errors = \DateTimeImmutable::getLastErrors();

        return false === $taken || (false !== $errors && $errors['warning_count'] > 0) || (int) $taken->format('Y') < 1900 ? null : $taken;
    }
}
