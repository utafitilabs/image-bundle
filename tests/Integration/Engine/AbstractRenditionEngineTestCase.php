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

namespace UtafitiLabs\ImageBundle\Tests\Integration\Engine;

use Imagine\Driver\Info;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Metadata\ExifMetadataReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImageBundle\Engine\ClearPixelScanner;
use UtafitiLabs\ImageBundle\Engine\RenditionEngine;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Exception\UnsupportedFormatException;
use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;
use UtafitiLabs\ImageBundle\Tests\Double\Profiles;
use UtafitiLabs\ImageBundle\Tests\Double\SyntheticImage;

/**
 * The engine's behaviour, the same for every driver; each driver's test case says how its Imagine is made.
 */
abstract class AbstractRenditionEngineTestCase extends TestCase
{
    abstract protected static function driver(): string;

    abstract protected function imagine(): ImagineInterface;

    abstract protected function driverInfo(): Info;

    protected function engine(?Info $info = null): RenditionEngine
    {
        $imagine = $this->imagine();
        $imagine->setMetadataReader(new ExifMetadataReader());

        return new RenditionEngine($imagine, new ClearPixelScanner(), $info ?? $this->driverInfo(), static::driver());
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return list<string>
     */
    protected function draw(array $profile, string $bytes): array
    {
        $made = Profiles::make($profile);

        return $this->engine()->draw($made, $bytes, $made->renditions());
    }

    /**
     * @param list<string> $renditions
     *
     * @return list<array{int, int}>
     */
    protected static function sizes(array $renditions): array
    {
        return array_map(SyntheticImage::size(...), $renditions);
    }

    public function testWidthSizesAreNeverLargerThanTheOriginal(): void
    {
        $renditions = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'sizes' => [320, 800, 2000]], SyntheticImage::jpeg(1600, 1200));

        self::assertSame([[320, 240], [800, 600], [1600, 1200]], self::sizes($renditions));
        foreach ($renditions as $rendition) {
            self::assertStringStartsWith("\xFF\xD8", $rendition);
        }
    }

    public function testUpscaleAllowsALargerRendition(): void
    {
        $renditions = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'upscale' => true, 'sizes' => [2000]], SyntheticImage::jpeg(1600, 1200));

        self::assertSame([[2000, 1500]], self::sizes($renditions));
    }

    public function testHeightSizes(): void
    {
        $renditions = $this->draw(['accept' => ['image/png'], 'format' => 'png', 'fit' => 'height', 'sizes' => [400, 1920, 2400]], SyntheticImage::clearPng(1000, 2000, [100, 100, 800, 1800]));

        self::assertSame([[200, 400], [960, 1920], [1000, 2000]], self::sizes($renditions));
    }

    public function testContainSizesTheLongestSide(): void
    {
        $renditions = $this->draw(['accept' => ['image/png'], 'format' => 'png', 'fit' => 'contain', 'sizes' => [256, 512, 1024]], SyntheticImage::opaquePng(300, 600));

        self::assertSame([[128, 256], [256, 512], [300, 600]], self::sizes($renditions));
    }

    public function testCoverCutsTheAspectFromTheMiddle(): void
    {
        $renditions = $this->draw(['accept' => ['image/png'], 'format' => 'png', 'fit' => 'cover', 'aspect' => '1:1', 'sizes' => [100]], SyntheticImage::bands(250, 500, 250, 500));

        self::assertSame([[100, 100]], self::sizes($renditions));
        foreach ([[0, 0], [99, 99], [0, 99], [50, 50]] as [$x, $y]) {
            [$red, $green, $blue] = SyntheticImage::pixel($renditions[0], $x, $y);
            self::assertGreaterThan(150, $green, \sprintf('pixel %d,%d', $x, $y));
            self::assertLessThan(80, $red, \sprintf('pixel %d,%d', $x, $y));
            self::assertLessThan(100, $blue, \sprintf('pixel %d,%d', $x, $y));
        }
    }

    public function testCoverWidthsStopAtTheCrop(): void
    {
        $renditions = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'fit' => 'cover', 'aspect' => '4:5', 'sizes' => [320, 800, 1080]], SyntheticImage::jpeg(1600, 1200));

        self::assertSame([[320, 400], [800, 1000], [960, 1200]], self::sizes($renditions));
    }

    public function testEachFormatIsWritten(): void
    {
        $profile = ['accept' => ['image/png'], 'background' => '#FFFFFF', 'format' => 'png', 'sizes' => [100 => ['format' => 'jpeg'], 200 => null, 300 => ['format' => 'webp']]];
        if ($this->driverInfo()->isFormatSupported('avif')) {
            $profile['sizes'][400] = ['format' => 'avif'];
        }

        $renditions = $this->draw($profile, SyntheticImage::opaquePng(500, 250));

        self::assertStringStartsWith("\xFF\xD8\xFF", $renditions[0]);
        self::assertStringStartsWith("\x89PNG\r\n\x1A\n", $renditions[1]);
        self::assertStringStartsWith('RIFF', $renditions[2]);
        self::assertSame('WEBP', substr($renditions[2], 8, 4));
        if (isset($renditions[3])) {
            self::assertSame('ftypavif', substr($renditions[3], 4, 8));
        }
    }

    public function testAFormatTheDriverCannotWriteIsRefusedClearly(): void
    {
        $profile = Profiles::make(['accept' => ['image/jpeg'], 'format' => 'avif', 'sizes' => [100]]);

        $this->expectException(UnsupportedFormatException::class);
        $this->expectExceptionMessage(\sprintf('The %s driver cannot write avif on this server', static::driver()));

        $this->engine(new NoAvifDriverInfo($this->driverInfo()))->draw($profile, SyntheticImage::jpeg(200, 100), $profile->renditions());
    }

    public function testQualityChangesTheBytes(): void
    {
        $source = SyntheticImage::bands(100, 100, 100, 200);
        [$low] = $this->draw(['accept' => ['image/png'], 'background' => '#FFFFFF', 'format' => 'jpeg', 'quality' => 10, 'sizes' => [300]], $source);
        [$high] = $this->draw(['accept' => ['image/png'], 'background' => '#FFFFFF', 'format' => 'jpeg', 'quality' => 95, 'sizes' => [300]], $source);

        self::assertLessThan(\strlen($high), \strlen($low));
    }

    public function testInterlaceWritesAProgressiveJpegAndNeverAnInterlacedPng(): void
    {
        [$progressive, $png] = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'interlace' => true, 'sizes' => [320 => null, 640 => ['format' => 'png']]], SyntheticImage::jpeg(800, 600));
        [$baseline] = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'sizes' => [320]], SyntheticImage::jpeg(800, 600));

        self::assertStringContainsString("\xFF\xC2", $progressive);
        self::assertStringNotContainsString("\xFF\xC2", $baseline);
        self::assertSame("\0", $png[28], 'the PNG interlace method');
    }

    public function testATransparentPngComesOutOnTheBackgroundNeverOnBlack(): void
    {
        $source = SyntheticImage::clearPng(200, 100, [50, 25, 100, 50]);

        [$jpeg] = $this->draw(['accept' => ['image/png'], 'background' => '#FFFFFF', 'format' => 'jpeg', 'sizes' => [200]], $source);
        [$webp] = $this->draw(['accept' => ['image/png'], 'background' => '#FFFFFF', 'format' => 'webp', 'sizes' => [200]], $source);

        foreach (['jpeg' => $jpeg, 'webp' => $webp] as $format => $rendition) {
            [$red, $green, $blue, $alpha] = SyntheticImage::pixel($rendition, 5, 5);
            self::assertGreaterThanOrEqual(245, min($red, $green, $blue), $format.': the clear corner is white');
            self::assertSame(0, $alpha, $format.': flattened');
            [$red, $green, $blue] = SyntheticImage::pixel($rendition, 100, 50);
            self::assertGreaterThan(150, $green, $format.': the figure keeps its colour');
            self::assertLessThan(80, $red, $format.': the figure keeps its colour');
        }
    }

    public function testTransparencyIsKeptWithoutABackground(): void
    {
        [$webp, $png] = $this->draw(['accept' => ['image/png'], 'format' => 'png', 'sizes' => [200 => null, 100 => ['format' => 'webp']]], SyntheticImage::clearPng(200, 100, [50, 25, 100, 50]));

        self::assertSame(127, SyntheticImage::pixel($webp, 2, 2)[3]);
        self::assertSame(127, SyntheticImage::pixel($png, 5, 5)[3]);
        self::assertSame(0, SyntheticImage::pixel($png, 100, 50)[3]);
    }

    /**
     * @return iterable<string, array{int, array{int, int}, array{int, int}}>
     */
    public static function orientations(): iterable
    {
        yield '1, as stored' => [1, [300, 200], [10, 10]];
        yield '2, mirrored' => [2, [300, 200], [289, 10]];
        yield '3, upside down' => [3, [300, 200], [289, 189]];
        yield '4, mirrored upside down' => [4, [300, 200], [10, 189]];
        yield '5, transposed' => [5, [200, 300], [10, 10]];
        yield '6, turned clockwise' => [6, [200, 300], [189, 10]];
        yield '7, transversed' => [7, [200, 300], [189, 289]];
        yield '8, turned anticlockwise' => [8, [200, 300], [10, 289]];
    }

    /**
     * @param array{int, int} $size
     * @param array{int, int} $red  a pixel inside the red block that sits in the stored top-left corner
     */
    #[DataProvider('orientations')]
    public function testTheExifOrientationIsApplied(int $orientation, array $size, array $red): void
    {
        [$rendition] = $this->draw(['accept' => ['image/jpeg'], 'format' => 'png', 'sizes' => [1000]], SyntheticImage::jpeg(300, 200, orientation: $orientation));

        self::assertSame($size, SyntheticImage::size($rendition));
        [$r, , $b] = SyntheticImage::pixel($rendition, $red[0], $red[1]);
        self::assertGreaterThan(150, $r);
        self::assertLessThan(100, $b);
    }

    public function testOrientCanBeTurnedOff(): void
    {
        [$rendition] = $this->draw(['accept' => ['image/jpeg'], 'orient' => false, 'format' => 'png', 'sizes' => [1000]], SyntheticImage::jpeg(300, 200, orientation: 6));

        self::assertSame([300, 200], SyntheticImage::size($rendition));
    }

    public function testMetadataIsNeverCopied(): void
    {
        $source = SyntheticImage::jpeg(400, 300, orientation: 1, takenAt: '2026:03:14 15:09:26');
        self::assertStringContainsString("Exif\0\0", $source);

        [$rendition] = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'sizes' => [200]], $source);

        self::assertStringNotContainsString("Exif\0\0", $rendition);
        self::assertStringNotContainsString('2026:03:14', $rendition);
    }

    /**
     * @return iterable<string, array{string, array{int, int}, array{int, int}, int}>
     */
    public static function trims(): iterable
    {
        yield 'a clear margin is cut' => [SyntheticImage::clearPng(100, 80, [20, 10, 40, 30]), [40, 30], [0, 0], 0];
        yield 'no clear margin' => [self::opaqueWithClearPixel(), [100, 80], [0, 0], 0];
        yield 'a solid rectangle' => [SyntheticImage::opaquePng(100, 80), [100, 80], [0, 0], 0];
        yield 'a fully clear picture' => [SyntheticImage::clearPng(100, 80, null), [100, 80], [0, 0], 127];
        yield 'a single opaque pixel' => [SyntheticImage::clearPng(100, 80, [33, 44, 1, 1]), [1, 1], [0, 0], 0];
        yield 'an almost clear pixel is kept' => [self::almostClearCorner(), [70, 60], [69, 59], 0];
    }

    /**
     * @param array{int, int} $size
     * @param array{int, int} $probe
     */
    #[DataProvider('trims')]
    public function testTrimClearCutsOnlyFullyTransparentRowsAndColumns(string $source, array $size, array $probe, int $alpha): void
    {
        [$rendition] = $this->draw(['accept' => ['image/png'], 'format' => 'png', 'fit' => 'contain', 'trim' => 'clear', 'sizes' => [512]], $source);

        self::assertSame($size, SyntheticImage::size($rendition));
        self::assertSame($alpha, SyntheticImage::pixel($rendition, $probe[0], $probe[1])[3]);
    }

    public function testTrimThenFitTheLongestSide(): void
    {
        [$badge] = $this->draw(['accept' => ['image/png'], 'format' => 'png', 'fit' => 'contain', 'trim' => 'clear', 'sizes' => [64]], SyntheticImage::clearPng(1000, 1000, [100, 300, 400, 200]));

        self::assertSame([64, 32], SyntheticImage::size($badge));
    }

    public function testAnOriginalThatFitsIsReusedAsItIs(): void
    {
        $source = SyntheticImage::jpeg(600, 400, takenAt: '2026:03:14 15:09:26');

        $renditions = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'reuse_original_if_fits' => true, 'sizes' => [300, 600, 1200]], $source);

        self::assertNotSame($source, $renditions[0]);
        self::assertSame([300, 200], SyntheticImage::size($renditions[0]));
        self::assertSame($source, $renditions[1]);
        self::assertSame($source, $renditions[2]);
    }

    public function testAnOriginalIsNotReusedWhenItMustTurnOrChangeFormat(): void
    {
        $turned = SyntheticImage::jpeg(600, 400, orientation: 6);
        [$rendition] = $this->draw(['accept' => ['image/jpeg'], 'format' => 'jpeg', 'reuse_original_if_fits' => true, 'sizes' => [1200]], $turned);
        self::assertNotSame($turned, $rendition);
        self::assertSame([400, 600], SyntheticImage::size($rendition));

        $plain = SyntheticImage::jpeg(600, 400);
        [$webp] = $this->draw(['accept' => ['image/jpeg'], 'format' => 'webp', 'reuse_original_if_fits' => true, 'sizes' => [1200]], $plain);
        self::assertSame('WEBP', substr($webp, 8, 4));
    }

    public function testAPaletteGifBecomesAPng(): void
    {
        [$rendition] = $this->draw(['accept' => ['image/gif'], 'sizes' => [100]], SyntheticImage::gif(50, 40));

        self::assertStringStartsWith("\x89PNG", $rendition);
        self::assertSame([50, 40], SyntheticImage::size($rendition));
    }

    public function testAWebpOriginalStaysWebp(): void
    {
        [$rendition] = $this->draw(['accept' => ['image/webp'], 'sizes' => [100]], SyntheticImage::webp(300, 150));

        self::assertSame('WEBP', substr($rendition, 8, 4));
        self::assertSame([100, 50], SyntheticImage::size($rendition));
    }

    public function testBytesThatAreNoImageAreUnreadable(): void
    {
        $profile = Profiles::make(['sizes' => [100]]);

        try {
            $this->engine()->draw($profile, 'not an image at all', $profile->renditions());
            self::fail('No exception.');
        } catch (UnreadableImageException $exception) {
            self::assertSame(FailureReasonEnum::Unreadable, $exception->reason);
        }
    }

    public function testATruncatedImageIsUnreadable(): void
    {
        $profile = Profiles::make(['sizes' => [100], 'accept' => ['image/png'], 'format' => 'png']);

        $this->expectException(UnreadableImageException::class);

        $this->engine()->draw($profile, substr(SyntheticImage::opaquePng(300, 200), 0, 60), $profile->renditions());
    }

    public function testALargeOriginalIsDrawnWithinSaneMemory(): void
    {
        $source = SyntheticImage::jpeg(4000, 3000, quality: 70);
        $profile = Profiles::make([
            'accept' => ['image/jpeg'],
            'format' => 'webp',
            'sizes' => [320, 800, 1280, 2000],
            'fallback' => ['size' => 1280, 'format' => 'jpeg', 'interlace' => true],
        ]);
        $engine = $this->engine();
        gc_collect_cycles();
        $before = memory_get_usage();
        memory_reset_peak_usage();

        $renditions = $engine->draw($profile, $source, $profile->renditions());

        self::assertSame([[320, 240], [800, 600], [1280, 960], [2000, 1500], [1280, 960]], self::sizes($renditions));
        self::assertLessThan(64 * 1024 * 1024, memory_get_peak_usage() - $before, 'PHP memory taken while drawing');
    }

    public function testTheScannerFindsAClearEdge(): void
    {
        $scanner = new ClearPixelScanner();
        $load = fn (string $bytes) => $this->imagine()->load($bytes);

        self::assertTrue($scanner->hasClearEdge($load(SyntheticImage::clearPng(100, 80, [10, 10, 80, 60]))));
        self::assertFalse($scanner->hasClearEdge($load(SyntheticImage::opaquePng(100, 80))));
        self::assertFalse($scanner->hasClearEdge($load(SyntheticImage::clearPng(100, 80, [0, 0, 100, 80]))));
        self::assertTrue($scanner->hasClearEdge($load(self::opaqueWithClearPixel(99, 40))));
        self::assertFalse($scanner->hasClearEdge($load(self::almostClearEdge())));
    }

    private static function opaqueWithClearPixel(int $x = 50, int $y = 40): string
    {
        $image = SyntheticImage::canvas(100, 80, SyntheticImage::GREEN);
        SyntheticImage::block($image, $x, $y, 1, 1, SyntheticImage::GREEN, 127);

        return SyntheticImage::png($image);
    }

    private static function almostClearCorner(): string
    {
        $image = imagecreatetruecolor(100, 80);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 127));
        SyntheticImage::block($image, 0, 0, 1, 1, SyntheticImage::GREEN, 126);
        SyntheticImage::block($image, 60, 50, 10, 10, SyntheticImage::GREEN);

        return SyntheticImage::png($image);
    }

    private static function almostClearEdge(): string
    {
        $image = imagecreatetruecolor(100, 80);
        imagealphablending($image, false);
        imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, 0, 0, 0, 126));
        SyntheticImage::block($image, 10, 10, 80, 60, SyntheticImage::GREEN);

        return SyntheticImage::png($image);
    }
}
