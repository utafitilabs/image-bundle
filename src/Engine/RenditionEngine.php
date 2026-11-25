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

use Imagine\Driver\Info;
use Imagine\Exception\Exception as ImagineException;
use Imagine\Filter\Basic\Autorotate;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\ImagineInterface;
use Imagine\Image\Point;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Exception\UnsupportedFormatException;
use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;
use UtafitiLabs\ImageBundle\Model\FitEnum;
use UtafitiLabs\ImageBundle\Model\FormatEnum;
use UtafitiLabs\ImageBundle\Model\Profile;
use UtafitiLabs\ImageBundle\Model\RenditionSpec;
use UtafitiLabs\ImageBundle\Model\TrimEnum;

/**
 * Draws a profile's renditions from an original's bytes with Imagine (https://imagine.readthedocs.io/en/stable/):
 * load(), the EXIF orientation applied by Imagine's own Autorotate filter from the metadata its ExifMetadataReader
 * reads (vendor/imagine/imagine/src/Filter/Basic/Autorotate.php), strip() so no metadata is carried over (GD keeps
 * pixels alone; Imagick drops its profiles and comments), the clear margin cut, the aspect cut from the middle, then
 * resize() and get() with the format's options (vendor/imagine/imagine/src/Gd/Image.php, finalizeOptions():
 * jpeg_quality, png_compression_level, webp_quality, avif_quality).
 *
 * The renditions are made largest first, each scaled down from the one before it, so the decoded original is held once
 * and never copied per size. Transparency is flattened onto the profile's background by pasting onto a canvas of
 * that colour (Imagine's paste() blends alpha, vendor/imagine/imagine/src/Gd/Image.php).
 */
final readonly class RenditionEngine
{
    public function __construct(
        private ImagineInterface $imagine,
        private ClearPixelScanner $scanner,
        private Info $driverInfo,
        private string $driver,
    ) {
    }

    /**
     * The encoded bytes of each rendition, in the order given.
     *
     * @param list<RenditionSpec> $specs
     *
     * @return list<string>
     *
     * @throws UnreadableImageException   when the bytes are no picture the driver can decode
     * @throws UnsupportedFormatException when the driver cannot write a rendition's format
     */
    public function draw(Profile $profile, string $bytes, array $specs): array
    {
        $source = self::format($bytes) ?? throw new UnreadableImageException(FailureReasonEnum::Unreadable, 'The original');
        foreach ($specs as $spec) {
            $format = $spec->formatFor($source);
            if (!$this->driverInfo->isFormatSupported($format->value)) {
                throw new UnsupportedFormatException($this->driver, $format->value);
            }
        }

        try {
            $image = $this->imagine->load($bytes);
        } catch (ImagineException $exception) {
            throw new UnreadableImageException(FailureReasonEnum::Unreadable, 'The original', $exception);
        }

        $turned = false;
        if ($profile->orient) {
            $autorotate = new Autorotate();
            $turned = [] !== $autorotate->getTransformations($image);
            $autorotate->apply($image);
        }
        $image->strip();
        if (TrimEnum::Clear === $profile->trim && null !== $box = $this->scanner->box($image)) {
            $image->crop(new Point($box[0], $box[1]), new Box($box[2], $box[3]));
        }
        if (FitEnum::Cover === $profile->fit && null !== $profile->aspect) {
            [$x, $y, $width, $height] = Geometry::coverCrop($image->getSize()->getWidth(), $image->getSize()->getHeight(), $profile->aspect);
            $image->crop(new Point($x, $y), new Box($width, $height));
        }
        $width = $image->getSize()->getWidth();
        $height = $image->getSize()->getHeight();

        $targets = [];
        foreach ($specs as $index => $spec) {
            $targets[$index] = Geometry::target($profile->fit, $profile->aspect, $profile->upscale, $spec->size, $width, $height);
        }
        $order = array_keys($targets);
        usort($order, static fn (int $a, int $b): int => $targets[$b][0] * $targets[$b][1] <=> $targets[$a][0] * $targets[$a][1]);

        $drawn = [];
        foreach ($order as $index) {
            $spec = $specs[$index];
            [$targetWidth, $targetHeight] = $targets[$index];
            $format = $spec->formatFor($source);
            if ($profile->reuseOriginalIfFits && $format === $source && !$turned && null === $profile->background && [$width, $height] === [$targetWidth, $targetHeight]) {
                $drawn[$index] = $bytes;
                continue;
            }
            if ($image->getSize()->getWidth() !== $targetWidth || $image->getSize()->getHeight() !== $targetHeight) {
                $image->resize(new Box($targetWidth, $targetHeight));
            }
            $drawn[$index] = $this->encode($image, $spec, $format, $profile->background);
        }
        ksort($drawn);

        return array_values($drawn);
    }

    /** The format of an original, read from its header; null when it is none the bundle draws from. */
    public static function format(string $bytes): ?FormatEnum
    {
        $size = @getimagesizefromstring($bytes);

        return false === $size ? null : FormatEnum::fromMimeType($size['mime']);
    }

    private function encode(ImageInterface $image, RenditionSpec $spec, FormatEnum $format, ?string $background): string
    {
        $target = $image;
        if (null !== $background) {
            $target = $this->imagine->create($image->getSize(), $image->palette()->color($background, 100));
            $target->paste($image, new Point(0, 0));
        }
        $quality = $spec->quality ?? $format->defaultQuality();
        $options = match ($format) {
            FormatEnum::Jpeg => ['jpeg_quality' => $quality],
            FormatEnum::Png => ['png_compression_level' => 9],
            FormatEnum::Webp => ['webp_quality' => $quality],
            FormatEnum::Avif => ['avif_quality' => $quality],
        };
        $target->interlace($spec->interlace && FormatEnum::Jpeg === $format ? ImageInterface::INTERLACE_LINE : ImageInterface::INTERLACE_NONE);

        return $target->get($format->value, $options);
    }
}
