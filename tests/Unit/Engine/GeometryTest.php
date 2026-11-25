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

namespace UtafitiLabs\ImageBundle\Tests\Unit\Engine;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImageBundle\Engine\Geometry;
use UtafitiLabs\ImageBundle\Model\FitEnum;

final class GeometryTest extends TestCase
{
    /**
     * @return iterable<string, array{FitEnum, bool, int, int, int, array{int, int}}>
     */
    public static function targets(): iterable
    {
        yield 'width, smaller' => [FitEnum::Width, false, 320, 1600, 1200, [320, 240]];
        yield 'width, never larger' => [FitEnum::Width, false, 2000, 1600, 1200, [1600, 1200]];
        yield 'width, upscaled' => [FitEnum::Width, true, 2000, 1600, 1200, [2000, 1500]];
        yield 'width, rounded' => [FitEnum::Width, false, 100, 300, 200, [100, 67]];
        yield 'width, never zero high' => [FitEnum::Width, false, 10, 4000, 1, [10, 1]];
        yield 'height, smaller' => [FitEnum::Height, false, 400, 1000, 2000, [200, 400]];
        yield 'height, never larger' => [FitEnum::Height, false, 1920, 1000, 1500, [1000, 1500]];
        yield 'contain, landscape' => [FitEnum::Contain, false, 256, 600, 300, [256, 128]];
        yield 'contain, portrait' => [FitEnum::Contain, false, 256, 300, 600, [128, 256]];
        yield 'contain, never larger' => [FitEnum::Contain, false, 512, 400, 300, [400, 300]];
        yield 'contain, upscaled' => [FitEnum::Contain, true, 800, 400, 300, [800, 600]];
    }

    /**
     * @param array{int, int} $expected
     */
    #[DataProvider('targets')]
    public function testTheTargetSize(FitEnum $fit, bool $upscale, int $size, int $width, int $height, array $expected): void
    {
        self::assertSame($expected, Geometry::target($fit, null, $upscale, $size, $width, $height));
    }

    /**
     * @return iterable<string, array{int, int, array{int, int}, array{int, int, int, int}}>
     */
    public static function crops(): iterable
    {
        yield 'landscape to 4:5' => [1600, 1200, [4, 5], [320, 0, 960, 1200]];
        yield 'portrait to 4:5' => [1000, 2000, [4, 5], [0, 375, 1000, 1250]];
        yield 'already 4:5' => [800, 1000, [4, 5], [0, 0, 800, 1000]];
        yield 'square from a landscape' => [1000, 500, [1, 1], [250, 0, 500, 500]];
    }

    /**
     * @param array{int, int}           $aspect
     * @param array{int, int, int, int} $expected
     */
    #[DataProvider('crops')]
    public function testTheCoverCropIsTheMiddle(int $width, int $height, array $aspect, array $expected): void
    {
        self::assertSame($expected, Geometry::coverCrop($width, $height, $aspect));
    }

    public function testCoverScalesTheCropToTheWidthAndNeverPastIt(): void
    {
        self::assertSame([320, 400], Geometry::target(FitEnum::Cover, [4, 5], false, 320, 960, 1200));
        self::assertSame([960, 1200], Geometry::target(FitEnum::Cover, [4, 5], false, 1080, 960, 1200));
        self::assertSame([1080, 1350], Geometry::target(FitEnum::Cover, [4, 5], true, 1080, 960, 1200));
    }
}
