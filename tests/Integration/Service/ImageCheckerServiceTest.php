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

namespace UtafitiLabs\ImageBundle\Tests\Integration\Service;

use Imagine\Gd\Imagine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use UtafitiLabs\ImageBundle\Engine\ClearPixelScanner;
use UtafitiLabs\ImageBundle\Engine\SvgCleaner;
use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Model\Refusal;
use UtafitiLabs\ImageBundle\Model\RefusalReasonEnum;
use UtafitiLabs\ImageBundle\Service\ImageCheckerService;
use UtafitiLabs\ImageBundle\Service\ProfileRegistryService;
use UtafitiLabs\ImageBundle\Tests\Double\Profiles;
use UtafitiLabs\ImageBundle\Tests\Double\SyntheticImage;

final class ImageCheckerServiceTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, ?Refusal}>
     */
    public static function uploads(): iterable
    {
        $jpeg = SyntheticImage::jpeg(1000, 800);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle r="4"/></svg>';
        $svgProfile = ['accept' => ['image/png', 'image/svg+xml'], 'svg' => 'sanitise', 'format' => 'png'];

        yield 'a JPEG it accepts' => [[], $jpeg, null];
        yield 'a type it does not accept' => [[], SyntheticImage::gif(10, 10), new Refusal(RefusalReasonEnum::Type)];
        yield 'text' => [[], 'plain words, no picture', new Refusal(RefusalReasonEnum::Type)];
        yield 'a PNG where only JPEG is accepted' => [['accept' => ['image/jpeg']], SyntheticImage::opaquePng(10, 10), new Refusal(RefusalReasonEnum::Type)];
        yield 'a WebP it accepts' => [['accept' => ['image/webp']], SyntheticImage::webp(10, 10), null];
        yield 'a JPEG whose header cannot be read' => [[], "\xFF\xD8\xFF\xE0".str_repeat("\x00", 40), new Refusal(RefusalReasonEnum::Unreadable)];
        yield 'too many bytes' => [['max_bytes' => 1000], $jpeg, new Refusal(RefusalReasonEnum::Bytes, 1000)];
        yield 'too many pixels' => [['max_pixels' => 799_999], $jpeg, new Refusal(RefusalReasonEnum::Pixels, 799_999)];
        yield 'exactly the pixels allowed' => [['max_pixels' => 800_000], $jpeg, null];
        yield 'too narrow' => [['min_width' => 1001], $jpeg, new Refusal(RefusalReasonEnum::MinWidth, 1001)];
        yield 'too short' => [['min_height' => 801], $jpeg, new Refusal(RefusalReasonEnum::MinHeight, 801)];
        yield 'a short side too short' => [['min_side' => 801], $jpeg, new Refusal(RefusalReasonEnum::MinSide, 801)];
        yield 'a long side too long' => [['max_side' => 999], $jpeg, new Refusal(RefusalReasonEnum::MaxSide, 999)];
        yield 'dimensions are the stored ones, not the turned ones' => [['min_width' => 1000, 'min_height' => 800], SyntheticImage::jpeg(1000, 800, orientation: 6), null];
        yield 'a clear edge' => [['accept' => ['image/png'], 'require_clear_edge' => true], SyntheticImage::clearPng(50, 50, [10, 10, 30, 30]), null];
        yield 'no clear edge' => [['accept' => ['image/png'], 'require_clear_edge' => true], SyntheticImage::opaquePng(50, 50), new Refusal(RefusalReasonEnum::ClearEdge)];
        yield 'a JPEG has no clear edge' => [['require_clear_edge' => true], $jpeg, new Refusal(RefusalReasonEnum::ClearEdge)];
        yield 'a PNG cut short' => [['accept' => ['image/png'], 'require_clear_edge' => true], substr(SyntheticImage::clearPng(50, 50, [10, 10, 30, 30]), 0, 60), new Refusal(RefusalReasonEnum::Unreadable)];
        yield 'an SVG it sanitises' => [$svgProfile, $svg, null];
        yield 'an SVG with an XML declaration' => [$svgProfile, '<?xml version="1.0" encoding="UTF-8"?>'."\n".$svg, null];
        yield 'an SVG it cannot read safely' => [$svgProfile, '<!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/hosts">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>', new Refusal(RefusalReasonEnum::Unreadable)];
        yield 'an SVG where none is accepted' => [[], $svg, new Refusal(RefusalReasonEnum::Type)];
        yield 'an SVG skips the pixel checks' => [$svgProfile + ['min_side' => 512], $svg, null];
    }

    /**
     * @param array<string, mixed> $profile
     */
    #[DataProvider('uploads')]
    public function testAnUploadIsCheckedAgainstTheProfile(array $profile, string $bytes, ?Refusal $expected): void
    {
        self::assertEquals($expected, $this->checker($profile)->check('upload', new File($this->file($bytes, 'upload.bin'))));
    }

    public function testTheTypeComesFromTheContentNeverTheName(): void
    {
        $refusal = $this->checker(['accept' => ['image/jpeg']])->check('upload', new File($this->file(SyntheticImage::opaquePng(10, 10), 'photo.jpg')));

        self::assertEquals(new Refusal(RefusalReasonEnum::Type), $refusal);
    }

    public function testAnUploadOverThePhpLimitIsTooManyBytes(): void
    {
        $file = new UploadedFile($this->file('', 'big.jpg'), 'big.jpg', 'image/jpeg', \UPLOAD_ERR_INI_SIZE, true);

        self::assertEquals(new Refusal(RefusalReasonEnum::Bytes, 2048), $this->checker(['max_bytes' => 2048])->check('upload', $file));
        self::assertEquals(new Refusal(RefusalReasonEnum::Bytes), $this->checker([])->check('upload', $file));
    }

    public function testAnUploadThatDidNotArriveIsSaidSo(): void
    {
        $file = new UploadedFile($this->file('', 'half.jpg'), 'half.jpg', 'image/jpeg', \UPLOAD_ERR_PARTIAL, true);

        self::assertEquals(new Refusal(RefusalReasonEnum::NotArrived), $this->checker([])->check('upload', $file));
    }

    public function testAnUnknownProfile(): void
    {
        $this->expectException(UnknownProfileException::class);
        $this->expectExceptionMessage('There is no image profile "poster"; the profiles are: upload.');

        $this->checker([])->check('poster', new File($this->file(SyntheticImage::jpeg(10, 10), 'a.jpg')));
    }

    public function testReasonsAreCodesNamedAfterTheProfileKeys(): void
    {
        self::assertSame(
            ['not_arrived', 'type', 'unreadable', 'bytes', 'pixels', 'min_width', 'min_height', 'min_side', 'max_side', 'clear_edge'],
            array_map(static fn (RefusalReasonEnum $reason): string => $reason->value, RefusalReasonEnum::cases()),
        );
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function checker(array $profile): ImageCheckerService
    {
        return new ImageCheckerService(
            new ProfileRegistryService(Profiles::processed(['upload' => $profile + ['sizes' => [100]]])),
            new Imagine(),
            new ClearPixelScanner(),
            new SvgCleaner(),
        );
    }

    private function file(string $bytes, string $name): string
    {
        $directory = sys_get_temp_dir().'/utafitilabs_image/uploads';
        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }
        $path = $directory.'/'.bin2hex(random_bytes(6)).'-'.$name;
        file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
