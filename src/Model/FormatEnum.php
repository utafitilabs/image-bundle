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

namespace UtafitiLabs\ImageBundle\Model;

/**
 * An encoding a rendition is written in.
 */
enum FormatEnum: string
{
    case Jpeg = 'jpeg';
    case Png = 'png';
    case Webp = 'webp';
    case Avif = 'avif';

    /**
     * The format a rendition in "format: original" takes for a source of this type; a GIF becomes a PNG.
     */
    public static function fromMimeType(string $mimeType): ?self
    {
        return match ($mimeType) {
            'image/jpeg' => self::Jpeg,
            'image/png', 'image/gif' => self::Png,
            'image/webp' => self::Webp,
            'image/avif' => self::Avif,
            default => null,
        };
    }

    /** The format of a stored name's extension; a GIF becomes a PNG, an SVG and anything else is null. */
    public static function fromExtension(string $extension): ?self
    {
        return match (strtolower($extension)) {
            'jpg', 'jpeg' => self::Jpeg,
            'png', 'gif' => self::Png,
            'webp' => self::Webp,
            'avif' => self::Avif,
            default => null,
        };
    }

    public function extension(): string
    {
        return self::Jpeg === $this ? 'jpg' : $this->value;
    }

    public function mimeType(): string
    {
        return 'image/'.$this->value;
    }

    public function keepsTransparency(): bool
    {
        return self::Jpeg !== $this;
    }

    /** The quality a lossy format is written at when the profile names none; PNG is lossless. */
    public function defaultQuality(): ?int
    {
        return match ($this) {
            self::Jpeg => 82,
            self::Webp => 74,
            self::Avif => 52,
            self::Png => null,
        };
    }
}
