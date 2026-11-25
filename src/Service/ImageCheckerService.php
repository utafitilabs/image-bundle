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

use Imagine\Exception\Exception as ImagineException;
use Imagine\Image\ImagineInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use UtafitiLabs\ImageBundle\Engine\ClearPixelScanner;
use UtafitiLabs\ImageBundle\Engine\SvgCleaner;
use UtafitiLabs\ImageBundle\Exception\UnknownProfileException;
use UtafitiLabs\ImageBundle\Model\Profile;
use UtafitiLabs\ImageBundle\Model\Refusal;
use UtafitiLabs\ImageBundle\Model\RefusalReasonEnum;

/**
 * The checks a profile declares for an upload, the ones it needs to be drawn safely: its type read from the content,
 * its bytes, its pixels, its sides on the stored (unturned) pixels, a clear edge, and for SVG that it can be cleaned.
 *
 * The type is never taken from the file's name or the type the browser sent: "they are considered not safe because a
 * malicious user could tamper with that information" (https://symfony.com/doc/current/controller/upload_file.html).
 * It is what fileinfo reads in the content ("finfo_buffer … Return information about a string buffer",
 * https://www.php.net/manual/en/function.finfo-buffer.php) and, for a raster, what getimagesizefromstring() reads in
 * its header must agree. Pixels are counted from the header before anything is decoded, so an upload too large to
 * decode is refused without decoding it; only require_clear_edge decodes the picture.
 */
final readonly class ImageCheckerService
{
    private const array RASTER = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'];

    public function __construct(
        private ProfileRegistryService $profiles,
        private ImagineInterface $imagine,
        private ClearPixelScanner $scanner,
        private SvgCleaner $svgCleaner,
    ) {
    }

    /**
     * Null when the upload may be stored under the profile; otherwise why not.
     *
     * @throws UnknownProfileException
     */
    public function check(string $profile, File $file): ?Refusal
    {
        $profile = $this->profiles->get($profile);
        if ($file instanceof UploadedFile && \UPLOAD_ERR_OK !== $file->getError()) {
            return \in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? new Refusal(RefusalReasonEnum::Bytes, $profile->maxBytes)
                : new Refusal(RefusalReasonEnum::NotArrived);
        }
        $bytes = @filesize($file->getPathname());
        if (false === $bytes) {
            return new Refusal(RefusalReasonEnum::NotArrived);
        }
        if (null !== $profile->maxBytes && $bytes > $profile->maxBytes) {
            return new Refusal(RefusalReasonEnum::Bytes, $profile->maxBytes);
        }
        $content = @file_get_contents($file->getPathname());
        if (false === $content) {
            return new Refusal(RefusalReasonEnum::NotArrived);
        }

        return $this->checkBytes($profile, $content);
    }

    /**
     * The type of a picture's content: one of the raster types whose header agrees, image/svg+xml for an SVG
     * document, or null.
     */
    public function mimeType(string $bytes): ?string
    {
        $found = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($bytes);
        if (\in_array($found, self::RASTER, true)) {
            $header = @getimagesizefromstring($bytes);

            return false !== $header && $header['mime'] === $found ? $found : null;
        }
        if (\in_array($found, ['image/svg+xml', 'image/svg', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true) && 1 === preg_match('/<svg[\s>\/]/', $bytes)) {
            return 'image/svg+xml';
        }

        return null;
    }

    private function checkBytes(Profile $profile, string $content): ?Refusal
    {
        $found = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);
        $type = $this->mimeType($content);
        if (null === $type) {
            return \in_array($found, self::RASTER, true) && $profile->accepts((string) $found)
                ? new Refusal(RefusalReasonEnum::Unreadable)
                : new Refusal(RefusalReasonEnum::Type);
        }
        if (!$profile->accepts($type)) {
            return new Refusal(RefusalReasonEnum::Type);
        }
        if ('image/svg+xml' === $type) {
            return null === $this->svgCleaner->clean($content) ? new Refusal(RefusalReasonEnum::Unreadable) : null;
        }

        $header = getimagesizefromstring($content);
        if (false === $header) {
            return new Refusal(RefusalReasonEnum::Unreadable);
        }
        [$width, $height] = $header;
        $limits = [
            [RefusalReasonEnum::Pixels, $profile->maxPixels, $width * $height > $profile->maxPixels],
            [RefusalReasonEnum::MinWidth, $profile->minWidth, $width < $profile->minWidth],
            [RefusalReasonEnum::MinHeight, $profile->minHeight, $height < $profile->minHeight],
            [RefusalReasonEnum::MinSide, $profile->minSide, min($width, $height) < $profile->minSide],
            [RefusalReasonEnum::MaxSide, $profile->maxSide, max($width, $height) > $profile->maxSide],
        ];
        foreach ($limits as [$reason, $limit, $broken]) {
            if (null !== $limit && $broken) {
                return new Refusal($reason, $limit);
            }
        }

        if ($profile->requireClearEdge) {
            if ('image/jpeg' === $type) {
                return new Refusal(RefusalReasonEnum::ClearEdge);
            }
            try {
                $image = $this->imagine->load($content);
            } catch (ImagineException) {
                return new Refusal(RefusalReasonEnum::Unreadable);
            }
            if (!$this->scanner->hasClearEdge($image)) {
                return new Refusal(RefusalReasonEnum::ClearEdge);
            }
        }

        return null;
    }
}
