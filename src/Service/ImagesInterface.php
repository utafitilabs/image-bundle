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

namespace UtafitiLabs\ImageBundle\Service;

use Symfony\Component\HttpFoundation\File\File;
use UtafitiLabs\ImageBundle\Exception\InvalidNameException;
use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Exception\UnknownSizeException;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Exception\UnsupportedFormatException;
use UtafitiLabs\ImageBundle\Model\Refusal;
use UtafitiLabs\ImageBundle\Model\StoredImage;

/**
 * Stores originals under a profile, makes their renditions and answers their addresses. Every method takes the
 * profile's name; a stored name is the one store() returned.
 */
interface ImagesInterface
{
    /**
     * Checks an upload against the profile's rules; null when it may be stored.
     *
     * @throws UnknownProfileException
     */
    public function check(string $profile, File $file): ?Refusal;

    /**
     * Keeps the original and makes or queues its renditions. The name is "<label>-<16 hex>.<ext>", or "<32 hex>.<ext>"
     * without a label; the extension follows the content. A string is the path of a local file.
     *
     * @throws UnknownProfileException
     * @throws InvalidNameException       when the label is not letters, digits, "_" and "-"
     * @throws UnreadableImageException   when the content is no picture the profile can draw
     * @throws UnsupportedFormatException when the driver cannot write a rendition's format
     */
    public function store(string $profile, File|string $source, ?string $label = null): StoredImage;

    /**
     * Keeps an original under a name the application chose (starting content, imports) and makes or queues its
     * renditions.
     *
     * @throws UnknownProfileException
     * @throws InvalidNameException
     * @throws UnreadableImageException
     * @throws UnsupportedFormatException
     */
    public function storeAs(string $profile, string $name, string $bytes): StoredImage;

    /**
     * The public address of a rendition; a sanitised SVG answers its one copy for every size.
     *
     * @throws UnknownProfileException
     * @throws UnknownSizeException
     * @throws InvalidNameException
     */
    public function url(string $profile, string $name, int $size): string;

    /**
     * The public address of the profile's fallback rendition.
     *
     * @throws UnknownProfileException
     * @throws UnknownSizeException    when the profile has no fallback
     * @throws InvalidNameException
     */
    public function fallbackUrl(string $profile, string $name): string;

    /**
     * "…-320.webp 320w, …": each size of a profile whose sizes are widths (fit: width or cover), described no wider
     * than the original when its width is given; a width already described is not repeated.
     *
     * @throws UnknownProfileException
     * @throws UnknownSizeException    when the profile's sizes are not widths
     * @throws InvalidNameException
     */
    public function srcset(string $profile, string $name, ?int $originalWidth = null): string;

    /**
     * Writes the missing renditions, or all of them, from the original; always synchronous. Returns the paths written.
     *
     * @return list<string>
     *
     * @throws UnknownProfileException
     * @throws InvalidNameException
     * @throws UnreadableImageException when the original is missing or cannot be read
     */
    public function redraw(string $profile, string $name, bool $all = false): array;

    /**
     * Deletes the original and every rendition.
     *
     * @throws UnknownProfileException
     * @throws InvalidNameException
     */
    public function delete(string $profile, string $name): void;
}
