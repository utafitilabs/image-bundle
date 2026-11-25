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

namespace UtafitiLabs\ImageBundle\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ConfigurationExtensionInterface;
use UtafitiLabs\ImageBundle\UtafitiLabsImageBundle;

final class ConfigurationTest extends TestCase
{
    private const array STORAGES = ['originals' => 'app.storage.originals', 'renditions' => 'app.storage.renditions'];

    public function testAProfileGetsTheDocumentedDefaults(): void
    {
        $config = $this->process(['storages' => self::STORAGES, 'profiles' => ['thumb' => ['sizes' => [320]]]]);

        self::assertSame('gd', $config['driver']);
        self::assertSame(self::STORAGES, $config['storages']);
        self::assertSame([
            'folder' => 'thumb',
            'originals' => null,
            'renditions' => null,
            'accept' => ['image/jpeg', 'image/png'],
            'max_bytes' => null,
            'max_pixels' => null,
            'min_width' => null,
            'min_height' => null,
            'min_side' => null,
            'max_side' => null,
            'require_clear_edge' => false,
            'orient' => true,
            'trim' => 'none',
            'fit' => 'width',
            'aspect' => null,
            'upscale' => false,
            'sizes' => [320 => ['path' => null, 'format' => null, 'quality' => null]],
            'format' => 'original',
            'quality' => null,
            'interlace' => false,
            'background' => null,
            'fallback' => null,
            'svg' => 'refuse',
            'path' => '{stem}-{size}.{ext}',
            'reuse_original_if_fits' => false,
            'cache_control' => 'public, max-age=31536000, immutable',
            'generate' => 'sync',
        ], $config['profiles']['thumb']);
    }

    public function testSizesAreAListOrAMapThatOverridesOneSize(): void
    {
        $config = $this->process(['storages' => self::STORAGES, 'profiles' => [
            'badge' => [
                'accept' => ['image/png'],
                'format' => 'png',
                'fit' => 'contain',
                'sizes' => [256 => ['path' => '{name}'], 512 => null, 1024 => ['format' => 'webp', 'quality' => 60]],
            ],
        ]]);

        self::assertSame([
            256 => ['path' => '{name}', 'format' => null, 'quality' => null],
            512 => ['path' => null, 'format' => null, 'quality' => null],
            1024 => ['path' => null, 'format' => 'webp', 'quality' => 60],
        ], $config['profiles']['badge']['sizes']);
    }

    /**
     * @return iterable<string, array{int|string, int}>
     */
    public static function byteLimits(): iterable
    {
        yield 'bytes' => [5000, 5000];
        yield 'kibibytes' => ['512K', 512 * 1024];
        yield 'mebibytes' => ['20M', 20 * 1024 * 1024];
        yield 'gibibytes, lower case' => ['1g', 1024 * 1024 * 1024];
        yield 'digits as a string' => ['2048', 2048];
    }

    #[DataProvider('byteLimits')]
    public function testMaxBytesTakesAUnit(int|string $given, int $bytes): void
    {
        $config = $this->process(['storages' => self::STORAGES, 'profiles' => ['thumb' => ['sizes' => [320], 'max_bytes' => $given]]]);

        self::assertSame($bytes, $config['profiles']['thumb']['max_bytes']);
    }

    public function testAProfileMayNameItsOwnStoragesAndAFallback(): void
    {
        $config = $this->process(['storages' => self::STORAGES, 'profiles' => ['picture' => [
            'originals' => 'app.storage.private',
            'renditions' => 'app.storage.cdn',
            'format' => 'webp',
            'background' => '#fff',
            'sizes' => [320, 1280],
            'fallback' => ['size' => 1280, 'format' => 'jpeg', 'quality' => 72, 'interlace' => true],
            'generate' => 'async',
        ]]]);

        $profile = $config['profiles']['picture'];
        self::assertSame('app.storage.private', $profile['originals']);
        self::assertSame('app.storage.cdn', $profile['renditions']);
        self::assertSame(['size' => 1280, 'format' => 'jpeg', 'quality' => 72, 'interlace' => true], $profile['fallback']);
        self::assertSame('async', $profile['generate']);
    }

    public function testAvifIsAcceptedInTheConfiguration(): void
    {
        $config = $this->process(['storages' => self::STORAGES, 'profiles' => ['thumb' => ['sizes' => [320], 'format' => 'avif', 'accept' => ['image/jpeg', 'image/avif']]]]);

        self::assertSame('avif', $config['profiles']['thumb']['format']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badConfigurations(): iterable
    {
        $profile = static fn (array $profile): array => ['storages' => self::STORAGES, 'profiles' => ['thumb' => $profile + ['sizes' => [320]]]];

        yield 'no storages' => [['profiles' => []], 'The child config "storages" under "utafitilabs_image" must be configured'];
        yield 'an unknown driver' => [['driver' => 'vips', 'storages' => self::STORAGES], 'The value "vips" is not allowed for path "utafitilabs_image.driver"'];
        yield 'a profile name with a space' => [['storages' => self::STORAGES, 'profiles' => ['two words' => ['sizes' => [320]]]], 'Profile "two words": a profile name is letters, digits, "_" and "-"'];
        yield 'no sizes' => [['storages' => self::STORAGES, 'profiles' => ['thumb' => []]], 'The child config "sizes" under "utafitilabs_image.profiles.thumb" must be configured'];
        yield 'an empty list of sizes' => [$profile(['sizes' => []]), 'Profile "thumb": "sizes" names at least one size'];
        yield 'a size of zero' => [$profile(['sizes' => [0, 320]]), 'Profile "thumb": every size is a whole number of pixels above 0, "0" is not'];
        yield 'a size that is not a number' => [$profile(['sizes' => ['large']]), 'Profile "thumb": every size is a whole number of pixels above 0, "large" is not'];
        yield 'cover without aspect' => [$profile(['fit' => 'cover']), 'Profile "thumb": "fit: cover" needs an "aspect" such as 4:5'];
        yield 'an aspect without cover' => [$profile(['aspect' => '4:5']), 'Profile "thumb": "aspect" applies to "fit: cover" only'];
        yield 'a malformed aspect' => [$profile(['fit' => 'cover', 'aspect' => '4x5']), 'Profile "thumb": "aspect" is two whole numbers above 0 as W:H, such as 4:5; "4x5" is not'];
        yield 'an unknown fit' => [$profile(['fit' => 'stretch']), 'The value "stretch" is not allowed for path "utafitilabs_image.profiles.thumb.fit"'];
        yield 'an unknown type' => [$profile(['accept' => ['image/tiff']]), 'The value "image/tiff" is not allowed for path "utafitilabs_image.profiles.thumb.accept'];
        yield 'a quality over 100' => [$profile(['quality' => 101]), 'The value 101 is too big for path "utafitilabs_image.profiles.thumb.quality"'];
        yield 'a malformed byte limit' => [$profile(['max_bytes' => '10Q']), 'Profile "thumb": "max_bytes" is a number of bytes, or one with K, M or G after it; "10Q" is not'];
        yield 'a malformed background' => [$profile(['background' => '#12345']), 'Profile "thumb": "background" is a colour as #RGB or #RRGGBB; "#12345" is not'];
        yield 'a JPEG from PNGs without a background' => [$profile(['format' => 'jpeg']), 'Profile "thumb": a JPEG has no transparency, so a profile that accepts image/png and writes JPEG needs a "background"'];
        yield 'a JPEG size from PNGs without a background' => [$profile(['format' => 'png', 'sizes' => [320 => ['format' => 'jpeg']]]), 'Profile "thumb": a JPEG has no transparency, so a profile that accepts image/png and writes JPEG needs a "background"'];
        yield 'a JPEG fallback from PNGs without a background' => [$profile(['format' => 'webp', 'fallback' => ['size' => 320, 'format' => 'jpeg']]), 'Profile "thumb": a JPEG has no transparency, so a profile that accepts image/png and writes JPEG needs a "background"'];
        yield 'a fallback in the original format' => [$profile(['fallback' => ['size' => 320, 'format' => 'original']]), 'The value "original" is not allowed for path "utafitilabs_image.profiles.thumb.fallback.format"'];
        yield 'SVG accepted but refused' => [$profile(['accept' => ['image/png', 'image/svg+xml']]), 'Profile "thumb": it accepts image/svg+xml, so it needs "svg: sanitise"'];
        yield 'SVG sanitised but not accepted' => [$profile(['svg' => 'sanitise']), 'Profile "thumb": "svg: sanitise" needs image/svg+xml in "accept"'];
        yield 'an unknown placeholder' => [$profile(['path' => '{stem}-{width}.{ext}']), 'Profile "thumb": "path" knows {name}, {stem}, {size} and {ext}; "{width}" is not one of them'];
        yield 'a path that climbs out of the folder' => [$profile(['path' => '../{stem}-{size}.{ext}']), 'Profile "thumb": "path" stays inside the folder: no "..", no leading "/"'];
        yield 'a folder that climbs out' => [$profile(['folder' => '../secrets']), 'Profile "thumb": "folder" is one or more names of letters, digits, "_", "-" and "." separated by "/", without ".."'];
        yield 'two sizes on one path' => [$profile(['path' => '{stem}.{ext}', 'sizes' => [320, 800]]), 'Profile "thumb": two renditions would be written to the same path "{stem}.{ext}"'];
        yield 'a fallback on a size\'s path' => [$profile(['format' => 'jpeg', 'background' => '#FFFFFF', 'fallback' => ['size' => 320, 'format' => 'jpeg']]), 'Profile "thumb": two renditions would be written to the same path "{stem}-{size}.{ext}"'];
        yield 'a minimum side over the maximum' => [$profile(['min_side' => 2000, 'max_side' => 1000]), 'Profile "thumb": "min_side" (2000) is over "max_side" (1000)'];
        yield 'a minimum width over the maximum side' => [$profile(['min_width' => 5000, 'max_side' => 4000]), 'Profile "thumb": "min_width" (5000) is over "max_side" (4000)'];
        yield 'reuse with cover' => [$profile(['fit' => 'cover', 'aspect' => '1:1', 'reuse_original_if_fits' => true]), 'Profile "thumb": "reuse_original_if_fits" cannot be used with "fit: cover" or "trim: clear", which always change the pixels'];
        yield 'reuse with trim' => [$profile(['trim' => 'clear', 'reuse_original_if_fits' => true]), 'Profile "thumb": "reuse_original_if_fits" cannot be used with "fit: cover" or "trim: clear", which always change the pixels'];
        yield 'an empty cache control' => [$profile(['cache_control' => '']), 'The path "utafitilabs_image.profiles.thumb.cache_control" cannot contain an empty value'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('badConfigurations')]
    public function testABadConfigurationIsRefusedWithAClearMessage(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        $this->process($config);
    }

    public function testTheImagickDriverNeedsItsExtension(): void
    {
        if (\extension_loaded('imagick')) {
            self::markTestSkipped('ext-imagick is loaded here, so the driver is allowed.');
        }

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The imagick driver needs the PHP extension "imagick", which is not loaded.');

        $this->process(['driver' => 'imagick', 'storages' => self::STORAGES]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{driver: string, storages: array{originals: string, renditions: string}, profiles: array<string, array<string, mixed>>}
     */
    private function process(array $config): array
    {
        $extension = (new UtafitiLabsImageBundle())->getContainerExtension();
        self::assertInstanceOf(ConfigurationExtensionInterface::class, $extension);
        $configuration = $extension->getConfiguration([], new ContainerBuilder());
        self::assertInstanceOf(ConfigurationInterface::class, $configuration);

        /** @var array{driver: string, storages: array{originals: string, renditions: string}, profiles: array<string, array<string, mixed>>} $processed */
        $processed = (new Processor())->processConfiguration($configuration, [$config]);

        return $processed;
    }
}
