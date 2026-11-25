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

/**
 * Test pictures drawn with GD in memory, so the suite carries no binary fixture.
 *
 * @phpstan-type Colour array{int<0, 255>, int<0, 255>, int<0, 255>}
 */
final class SyntheticImage
{
    public const array RED = [220, 30, 30];
    public const array GREEN = [30, 180, 60];
    public const array BLUE = [30, 60, 200];

    /**
     * A JPEG of one colour, with a red block in its stored top-left corner, and an optional EXIF block with an
     * orientation and the time it was taken.
     *
     * @param Colour $colour
     */
    public static function jpeg(int $width, int $height, array $colour = self::BLUE, ?int $orientation = null, ?string $takenAt = null, int $quality = 90): string
    {
        $image = self::canvas($width, $height, $colour);
        self::block($image, 0, 0, max(1, intdiv($width, 5)), max(1, intdiv($height, 5)), self::RED);
        ob_start();
        imagejpeg($image, null, $quality);
        $jpeg = (string) ob_get_clean();

        return null === $orientation && null === $takenAt ? $jpeg : self::withExif($jpeg, $orientation, $takenAt);
    }

    /**
     * A PNG with a clear background and an opaque block, as x, y, width, height; no block draws a fully clear PNG.
     *
     * @param ?array{int, int, int, int} $block
     * @param Colour                     $colour
     */
    public static function clearPng(int $width, int $height, ?array $block, array $colour = self::GREEN): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        if (null !== $block) {
            self::block($image, $block[0], $block[1], $block[2], $block[3], $colour);
        }

        return self::png($image);
    }

    /**
     * An opaque PNG of one colour.
     *
     * @param Colour $colour
     */
    public static function opaquePng(int $width, int $height, array $colour = self::GREEN): string
    {
        return self::png(self::canvas($width, $height, $colour));
    }

    /**
     * A PNG of three vertical bands, red, green and blue, of the given widths.
     */
    public static function bands(int $left, int $middle, int $right, int $height): string
    {
        $image = self::canvas($left + $middle + $right, $height, self::GREEN);
        self::block($image, 0, 0, $left, $height, self::RED);
        self::block($image, $left + $middle, 0, $right, $height, self::BLUE);

        return self::png($image);
    }

    public static function gif(int $width, int $height): string
    {
        $image = imagecreate(max(1, $width), max(1, $height));
        imagecolorallocate($image, 30, 60, 200);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    public static function webp(int $width, int $height): string
    {
        $image = self::canvas($width, $height, self::BLUE);
        ob_start();
        imagewebp($image, null, 80);

        return (string) ob_get_clean();
    }

    /**
     * @param Colour $colour
     */
    public static function canvas(int $width, int $height, array $colour): \GdImage
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...$colour));

        return $image;
    }

    /**
     * @param Colour      $colour
     * @param int<0, 127> $alpha
     */
    public static function block(\GdImage $image, int $x, int $y, int $width, int $height, array $colour, int $alpha = 0): void
    {
        imagealphablending($image, false);
        imagefilledrectangle($image, $x, $y, $x + $width - 1, $y + $height - 1, (int) imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], $alpha));
    }

    public static function png(\GdImage $image): string
    {
        imagesavealpha($image, true);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * The colour and GD alpha (0 opaque, 127 clear) of one pixel of encoded bytes.
     *
     * A PNG may declare its transparency as one transparent grey or colour in a tRNS chunk instead of an alpha
     * channel (https://www.w3.org/TR/png/#11tRNS); GD reads that as the image's transparent colour
     * (imagecolortransparent()) and leaves the pixel's own alpha at 0, so a pixel of that colour is reported as 127.
     *
     * @return array{int, int, int, int}
     */
    public static function pixel(string $bytes, int $x, int $y): array
    {
        $image = imagecreatefromstring($bytes);
        if (false === $image) {
            throw new \RuntimeException('Not an image.');
        }
        $colour = imagecolorat($image, $x, $y);
        if (false === $colour) {
            throw new \RuntimeException('No such pixel.');
        }
        $transparent = imagecolortransparent($image);
        if (!imageistruecolor($image)) {
            $index = imagecolorsforindex($image, $colour);

            return [$index['red'], $index['green'], $index['blue'], $colour === $transparent ? 127 : $index['alpha']];
        }

        return [$colour >> 16 & 0xFF, $colour >> 8 & 0xFF, $colour & 0xFF, $colour === $transparent ? 127 : $colour >> 24 & 0x7F];
    }

    /**
     * @return array{int, int}
     */
    public static function size(string $bytes): array
    {
        $size = getimagesizefromstring($bytes);
        if (false === $size) {
            throw new \RuntimeException('Not an image.');
        }

        return [$size[0], $size[1]];
    }

    /**
     * Inserts an APP1 EXIF segment after the JPEG's start of image: IFD0 with Orientation and a pointer to the EXIF
     * IFD with DateTimeOriginal (TIFF 6.0 and EXIF 2.32 layout, little-endian).
     */
    private static function withExif(string $jpeg, ?int $orientation, ?string $takenAt): string
    {
        $entries = [];
        if (null !== $orientation) {
            $entries[] = pack('vvVvv', 0x0112, 3, 1, $orientation, 0);
        }
        $ifd0Size = 2 + 12 * (\count($entries) + (null !== $takenAt ? 1 : 0)) + 4;
        $exifIfd = '';
        if (null !== $takenAt) {
            $exifOffset = 8 + $ifd0Size;
            $entries[] = pack('vvVV', 0x8769, 4, 1, $exifOffset);
            $valueOffset = $exifOffset + 2 + 12 + 4;
            $exifIfd = pack('v', 1).pack('vvVV', 0x9003, 2, \strlen($takenAt) + 1, $valueOffset).pack('V', 0).$takenAt."\0";
        }
        $tiff = 'II'.pack('vV', 42, 8).pack('v', \count($entries)).implode('', $entries).pack('V', 0).$exifIfd;
        $app1 = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', \strlen($app1) + 2).$app1.substr($jpeg, 2);
    }
}
