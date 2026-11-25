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

use UtafitiLabs\ImageBundle\Model\FitEnum;

/**
 * The arithmetic of a rendition's size, on whole pixels.
 */
final class Geometry
{
    /**
     * The width and height of a rendition of the given size from a picture of the given dimensions (for cover, the
     * dimensions of the crop already made); never larger than the picture unless upscaling is allowed.
     *
     * @param ?array{int, int} $aspect
     *
     * @return array{int, int}
     */
    public static function target(FitEnum $fit, ?array $aspect, bool $upscale, int $size, int $width, int $height): array
    {
        $measured = match ($fit) {
            FitEnum::Width, FitEnum::Cover => $width,
            FitEnum::Height => $height,
            FitEnum::Contain => max($width, $height),
        };
        if (!$upscale && $size >= $measured) {
            return [$width, $height];
        }
        if (FitEnum::Cover === $fit && null !== $aspect) {
            return [$size, max(1, (int) round($size * $aspect[1] / $aspect[0]))];
        }
        $scale = $size / $measured;

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    /**
     * The largest box of the aspect from the middle of a picture, as x, y, width and height.
     *
     * @param array{int, int} $aspect
     *
     * @return array{int, int, int, int}
     */
    public static function coverCrop(int $width, int $height, array $aspect): array
    {
        [$across, $down] = $aspect;
        if ($width * $down > $height * $across) {
            $cropWidth = max(1, intdiv($height * $across, $down));
            $cropHeight = $height;
        } else {
            $cropWidth = $width;
            $cropHeight = max(1, intdiv($width * $down, $across));
        }

        return [intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2), $cropWidth, $cropHeight];
    }
}
