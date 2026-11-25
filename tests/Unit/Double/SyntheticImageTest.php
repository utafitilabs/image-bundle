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

namespace UtafitiLabs\ImageBundle\Tests\Unit\Double;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImageBundle\Tests\Double\SyntheticImage;

/**
 * The pixel reader reports the transparency a PNG declares, in whichever form the encoder chose: an alpha channel, or
 * a tRNS chunk naming one transparent grey or colour (https://www.w3.org/TR/png/#11tRNS), which
 * GD reads as the image's transparent colour rather than as alpha.
 */
final class SyntheticImageTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function pngs(): iterable
    {
        yield 'an alpha channel, clear' => [SyntheticImage::clearPng(4, 3, null), 127];
        yield 'an alpha channel, opaque' => [SyntheticImage::opaquePng(4, 3), 0];
        yield 'greyscale with a transparent grey' => [self::png(0, pack('n', 0), "\0"), 127];
        yield 'greyscale with another transparent grey' => [self::png(0, pack('n', 200), "\0"), 0];
        yield 'truecolour with a transparent colour' => [self::png(2, pack('nnn', 0, 0, 0), "\0\0\0"), 127];
        yield 'truecolour with another transparent colour' => [self::png(2, pack('nnn', 9, 9, 9), "\0\0\0"), 0];
    }

    #[DataProvider('pngs')]
    public function testThePixelAlphaFollowsTheTransparencyThePngDeclares(string $png, int $alpha): void
    {
        self::assertSame($alpha, SyntheticImage::pixel($png, 1, 1)[3]);
    }

    /**
     * A 4 by 3 PNG whose every pixel is the given sample, with a tRNS chunk.
     */
    private static function png(int $colourType, string $transparent, string $sample): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $rows = str_repeat("\0".str_repeat($sample, 4), 3);

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', 4, 3, 8, $colourType, 0, 0, 0))
            .$chunk('tRNS', $transparent)
            .$chunk('IDAT', (string) gzcompress($rows))
            .$chunk('IEND', '');
    }
}
