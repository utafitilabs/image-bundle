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

use Imagine\Gd\Image as GdImage;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use Imagine\Imagick\Image as ImagickImage;

/**
 * Finds the fully transparent pixels of a decoded picture: the clear margin around its figure, and whether its edge
 * has a clear pixel.
 *
 * A pixel is clear when its alpha is fully transparent, GD's 127 ("alpha … between 0 and 127. 0 indicates completely
 * opaque while 127 indicates completely transparent", https://www.php.net/manual/en/function.imagecolorallocatealpha.php)
 * or 0 in ImageMagick's exported alpha channel ("A" in https://www.php.net/manual/en/imagick.exportimagepixels.php).
 * imagecropauto() with IMG_CROP_TRANSPARENT is not used: on GD 2.3.3 it leaves the clear margin of an alpha-channel
 * PNG uncut. Other drivers are read through Imagine's getColorAt(), whose alpha 0 is fully transparent
 * (vendor/imagine/imagine/src/Gd/Image.php, getColorAt()).
 */
final class ClearPixelScanner
{
    /**
     * The smallest box that holds every pixel not fully clear, as x, y, width and height; null when there is nothing
     * to cut: no clear margin, or every pixel clear.
     *
     * @return ?array{int, int, int, int}
     */
    public function box(ImageInterface $image): ?array
    {
        $width = $image->getSize()->getWidth();
        $height = $image->getSize()->getHeight();
        $clear = $this->clear($image);
        $row = static function (int $y) use ($width, $clear): bool {
            for ($x = 0; $x < $width; ++$x) {
                if (!$clear($x, $y)) {
                    return true;
                }
            }

            return false;
        };
        $top = 0;
        while ($top < $height && !$row($top)) {
            ++$top;
        }
        if ($top === $height) {
            return null;
        }
        $bottom = $height - 1;
        while (!$row($bottom)) {
            --$bottom;
        }
        $column = static function (int $x) use ($top, $bottom, $clear): bool {
            for ($y = $top; $y <= $bottom; ++$y) {
                if (!$clear($x, $y)) {
                    return true;
                }
            }

            return false;
        };
        $left = 0;
        while (!$column($left)) {
            ++$left;
        }
        $right = $width - 1;
        while (!$column($right)) {
            --$right;
        }
        $box = [$left, $top, $right - $left + 1, $bottom - $top + 1];

        return [0, 0, $width, $height] === $box ? null : $box;
    }

    /** Whether a pixel on the picture's edge is fully clear. */
    public function hasClearEdge(ImageInterface $image): bool
    {
        $width = $image->getSize()->getWidth();
        $height = $image->getSize()->getHeight();
        $clear = $this->clear($image);
        for ($x = 0; $x < $width; ++$x) {
            if ($clear($x, 0) || $clear($x, $height - 1)) {
                return true;
            }
        }
        for ($y = 1; $y < $height - 1; ++$y) {
            if ($clear(0, $y) || $clear($width - 1, $y)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return \Closure(int, int): bool
     */
    private function clear(ImageInterface $image): \Closure
    {
        $resource = $image instanceof GdImage ? $image->getGdResource() : null;
        if ($resource instanceof \GdImage) {
            return static fn (int $x, int $y): bool => 127 === ((int) imagecolorat($resource, $x, $y) >> 24 & 0x7F);
        }
        if ($image instanceof ImagickImage) {
            $imagick = $image->getImagick();
            $width = $imagick->getImageWidth();
            $rows = [];

            return static function (int $x, int $y) use ($imagick, $width, &$rows): bool {
                if (!isset($rows[$y])) {
                    if (\count($rows) > 4) {
                        $rows = [];
                    }
                    /** @var list<int> $alpha */
                    $alpha = $imagick->exportImagePixels(0, $y, $width, 1, 'A', \Imagick::PIXEL_CHAR);
                    $rows[$y] = $alpha;
                }

                return 0 === $rows[$y][$x];
            };
        }

        return static fn (int $x, int $y): bool => 0 === $image->getColorAt(new Point($x, $y))->getAlpha();
    }
}
