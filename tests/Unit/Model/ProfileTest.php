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

namespace UtafitiLabs\ImageBundle\Tests\Unit\Model;

use PHPUnit\Framework\TestCase;
use UtafitiLabs\ImageBundle\Model\FitEnum;
use UtafitiLabs\ImageBundle\Model\FormatEnum;
use UtafitiLabs\ImageBundle\Tests\Double\Profiles;

final class ProfileTest extends TestCase
{
    public function testPathsFollowThePatternInTheFolder(): void
    {
        $profile = Profiles::make([
            'folder' => 'badges/large',
            'accept' => ['image/png', 'image/svg+xml'],
            'svg' => 'sanitise',
            'fit' => 'contain',
            'format' => 'png',
            'sizes' => [512 => null, 256 => ['path' => '{name}']],
        ], 'badge');

        self::assertSame([256, 512], $profile->sizeList());
        self::assertSame(FitEnum::Contain, $profile->fit);
        self::assertSame('badges/large/ab12-0f.png', $profile->originalPath('ab12-0f.png'));
        $badge = $profile->size(256);
        $large = $profile->size(512);
        self::assertNotNull($badge);
        self::assertNotNull($large);
        self::assertSame('badges/large/ab12-0f.png', $profile->path($badge, 'ab12-0f.png', FormatEnum::Png));
        self::assertSame('badges/large/ab12-0f-512.png', $profile->path($large, 'ab12-0f.png', FormatEnum::Png));
        self::assertSame('badges/large/ab12-0f.svg', $profile->svgPath('ab12-0f.svg'));
        self::assertNull($profile->size(300));
    }

    public function testFormatOriginalKeepsTheOriginalsFormatAndTheFallbackComesLast(): void
    {
        $profile = Profiles::make([
            'sizes' => [800, 320],
            'background' => '#fff',
            'fallback' => ['size' => 800, 'format' => 'jpeg', 'quality' => 70],
            'path' => 'w{size}/{stem}.{ext}',
        ]);

        $renditions = $profile->renditions();
        self::assertCount(3, $renditions);
        self::assertSame('thumb/w320/c0ffee.png', $profile->path($renditions[0], 'c0ffee.png', FormatEnum::Png));
        self::assertSame('thumb/w800/c0ffee.jpg', $profile->path($renditions[1], 'c0ffee.jpg', FormatEnum::Jpeg));
        self::assertTrue($renditions[2]->fallback);
        self::assertSame('thumb/w800/c0ffee.jpg', $profile->path($renditions[2], 'c0ffee.png', FormatEnum::Png));
        self::assertSame(70, $renditions[2]->quality);
        self::assertSame('#FFFFFF', $profile->background);
    }

    public function testAnAspectIsTwoNumbers(): void
    {
        self::assertSame([4, 5], Profiles::make(['fit' => 'cover', 'aspect' => '4:5', 'sizes' => [320]])->aspect);
    }

    public function testFormatsKnowTheirExtensionTypeAndQuality(): void
    {
        self::assertSame('jpg', FormatEnum::Jpeg->extension());
        self::assertSame('image/webp', FormatEnum::Webp->mimeType());
        self::assertSame(FormatEnum::Png, FormatEnum::fromMimeType('image/gif'));
        self::assertNull(FormatEnum::fromMimeType('image/svg+xml'));
        self::assertSame([82, null, 74, 52], array_map(static fn (FormatEnum $f): ?int => $f->defaultQuality(), FormatEnum::cases()));
        self::assertFalse(FormatEnum::Jpeg->keepsTransparency());
        self::assertTrue(FormatEnum::Avif->keepsTransparency());
    }
}
