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

use Imagine\Image\Metadata\ExifMetadataReader;
use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImageBundle\Engine\ExifReader;
use UtafitiLabs\ImageBundle\Tests\Double\SyntheticImage;

final class ExifReaderTest extends TestCase
{
    public function testTheOrientationIsRead(): void
    {
        $reader = new ExifReader(new ExifMetadataReader());

        self::assertSame(6, $reader->orientation(SyntheticImage::jpeg(20, 10, orientation: 6)));
        self::assertSame(1, $reader->orientation(SyntheticImage::jpeg(20, 10)));
        self::assertSame(1, $reader->orientation(SyntheticImage::jpeg(20, 10, orientation: 9)));
        self::assertSame(1, $reader->orientation(SyntheticImage::opaquePng(20, 10)));
        self::assertSame(1, $reader->orientation('not an image'));
    }

    public function testWhenThePhotoWasTaken(): void
    {
        $reader = new ExifReader(new ExifMetadataReader());

        self::assertEquals(new \DateTimeImmutable('2026-03-14 15:09:26'), $reader->takenAt(SyntheticImage::jpeg(20, 10, takenAt: '2026:03:14 15:09:26')));
        self::assertNull($reader->takenAt(SyntheticImage::jpeg(20, 10, takenAt: '0000:00:00 00:00:00')));
        self::assertNull($reader->takenAt(SyntheticImage::jpeg(20, 10, takenAt: '2026:02:30 25:61:00')));
        self::assertNull($reader->takenAt(SyntheticImage::jpeg(20, 10, takenAt: 'yesterday at ten')));
        self::assertNull($reader->takenAt(SyntheticImage::jpeg(20, 10)));
    }
}
