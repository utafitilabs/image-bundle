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

use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Messenger\MessageBusInterface;
use UtafitiLabs\ImageBundle\Engine\ExifReader;
use UtafitiLabs\ImageBundle\Engine\RenditionEngine;
use UtafitiLabs\ImageBundle\Engine\SvgCleaner;
use UtafitiLabs\ImageBundle\Event\RenditionsFailed;
use UtafitiLabs\ImageBundle\Event\RenditionsWritten;
use UtafitiLabs\ImageBundle\Exception\InvalidNameException;
use UtafitiLabs\ImageBundle\Exception\UnknownSizeException;
use UtafitiLabs\ImageBundle\Exception\UnreadableImageException;
use UtafitiLabs\ImageBundle\Message\MakeRenditions;
use UtafitiLabs\ImageBundle\Model\FailureReasonEnum;
use UtafitiLabs\ImageBundle\Model\FitEnum;
use UtafitiLabs\ImageBundle\Model\FormatEnum;
use UtafitiLabs\ImageBundle\Model\GenerateEnum;
use UtafitiLabs\ImageBundle\Model\Profile;
use UtafitiLabs\ImageBundle\Model\Refusal;
use UtafitiLabs\ImageBundle\Model\RenditionSpec;
use UtafitiLabs\ImageBundle\Model\StoredImage;
use UtafitiLabs\ImageBundle\Model\SvgModeEnum;

/**
 * The bundle's public service. Both storages are Flysystem operators named by service id: "Once you have a
 * FilesystemOperator, you can call methods from the Filesystem API to interact with your storage"
 * (https://github.com/thephpleague/flysystem-bundle/blob/3.x/docs/1-getting-started.md). An address is the renditions
 * storage's publicUrl() (https://flysystem.thephpleague.com/docs/usage/public-urls/). Each rendition is written with
 * CacheControl and ContentType, two of the options an S3 adapter forwards to the object
 * (League\Flysystem\AsyncAwsS3\AsyncAwsS3Adapter::AVAILABLE_OPTIONS and AwsS3V3Adapter::AVAILABLE_OPTIONS); a local
 * adapter ignores them. An asynchronous profile dispatches MakeRenditions on the message bus
 * (https://symfony.com/doc/current/messenger.html#dispatching-the-message); events go through the PSR-14 dispatcher.
 */
final readonly class ImagesService implements ImagesInterface
{
    private const string SVG = 'image/svg+xml';

    public function __construct(
        private ProfileRegistryService $profiles,
        private ContainerInterface $storages,
        private RenditionEngine $engine,
        private ImageCheckerService $checker,
        private SvgCleaner $svgCleaner,
        private ExifReader $exif,
        private ?MessageBusInterface $bus = null,
        private ?EventDispatcherInterface $events = null,
    ) {
    }

    public function check(string $profile, File $file): ?Refusal
    {
        return $this->checker->check($profile, $file);
    }

    public function store(string $profile, File|string $source, ?string $label = null): StoredImage
    {
        $path = $source instanceof File ? $source->getPathname() : $source;
        $bytes = @file_get_contents($path);
        if (false === $bytes) {
            throw new UnreadableImageException(FailureReasonEnum::Missing, $path);
        }
        if (null !== $label && 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]*$/', $label)) {
            throw InvalidNameException::label($label);
        }
        $type = $this->checker->mimeType($bytes) ?? throw new UnreadableImageException(FailureReasonEnum::Unreadable, $path);
        $extension = match ($type) {
            self::SVG => 'svg',
            'image/gif' => 'gif',
            default => FormatEnum::fromMimeType($type)?->extension() ?? throw new UnreadableImageException(FailureReasonEnum::Unreadable, $path),
        };
        $name = (null === $label ? bin2hex(random_bytes(16)) : $label.'-'.bin2hex(random_bytes(8))).'.'.$extension;

        return $this->storeAs($profile, $name, $bytes);
    }

    public function storeAs(string $profile, string $name, string $bytes): StoredImage
    {
        $profile = $this->profiles->get($profile);
        self::validate($name);
        $type = $this->checker->mimeType($bytes);
        if (self::SVG === $type) {
            return $this->storeSvg($profile, $name, $bytes);
        }
        $format = null === $type ? null : RenditionEngine::format($bytes);
        $header = @getimagesizefromstring($bytes);
        if (null === $type || null === $format || false === $header) {
            throw new UnreadableImageException(FailureReasonEnum::Unreadable, $name);
        }

        $async = GenerateEnum::Async === $profile->generate;
        if ($async && null === $this->bus) {
            throw new \LogicException(\sprintf('The image profile "%s" makes its renditions through Messenger ("generate: async"), but no message bus is available; install symfony/messenger or set "generate: sync".', $profile->name));
        }
        $specs = $profile->renditions();
        $drawn = $async ? [] : $this->engine->draw($profile, $bytes, $specs);
        $this->originals($profile)->write($profile->originalPath($name), $bytes);
        if (null !== $this->bus && $async) {
            $this->bus->dispatch(new MakeRenditions($profile->name, $name));
        } else {
            $this->writeRenditions($profile, $name, $format, $specs, $drawn);
        }

        $turned = $profile->orient && $this->exif->orientation($bytes) >= 5;

        return new StoredImage(
            $name,
            $type,
            \strlen($bytes),
            $turned ? $header[1] : $header[0],
            $turned ? $header[0] : $header[1],
            hash('sha256', $bytes),
            $this->exif->takenAt($bytes),
        );
    }

    public function url(string $profile, string $name, int $size): string
    {
        $profile = $this->profiles->get($profile);
        $format = self::validate($name);
        if (null === $format) {
            return $this->renditionsOf($profile)->publicUrl($profile->svgPath($name));
        }
        $spec = $profile->size($size) ?? throw UnknownSizeException::size($profile->name, $size, $profile->sizeList());

        return $this->renditionsOf($profile)->publicUrl($profile->path($spec, $name, $format));
    }

    public function fallbackUrl(string $profile, string $name): string
    {
        $profile = $this->profiles->get($profile);
        $format = self::validate($name);
        if (null === $format) {
            return $this->renditionsOf($profile)->publicUrl($profile->svgPath($name));
        }
        $fallback = $profile->fallback ?? throw UnknownSizeException::noFallback($profile->name);

        return $this->renditionsOf($profile)->publicUrl($profile->path($fallback, $name, $format));
    }

    public function srcset(string $profile, string $name, ?int $originalWidth = null): string
    {
        $profile = $this->profiles->get($profile);
        $format = self::validate($name);
        if (null === $format) {
            return $this->renditionsOf($profile)->publicUrl($profile->svgPath($name));
        }
        if (FitEnum::Width !== $profile->fit && FitEnum::Cover !== $profile->fit) {
            throw UnknownSizeException::notWidths($profile->name);
        }
        $candidates = [];
        foreach ($profile->sizes as $spec) {
            $width = null === $originalWidth ? $spec->size : min($spec->size, $originalWidth);
            $candidates[$width] ??= $this->renditionsOf($profile)->publicUrl($profile->path($spec, $name, $format)).' '.$width.'w';
        }

        return implode(', ', $candidates);
    }

    public function redraw(string $profile, string $name, bool $all = false): array
    {
        $profile = $this->profiles->get($profile);
        $format = self::validate($name);
        $renditions = $this->renditionsOf($profile);
        $specs = [];
        if (null !== $format) {
            foreach ($profile->renditions() as $spec) {
                if ($all || !$renditions->fileExists($profile->path($spec, $name, $format))) {
                    $specs[] = $spec;
                }
            }
        }
        $svgMissing = null === $format && ($all || !$renditions->fileExists($profile->svgPath($name)));
        if ([] === $specs && !$svgMissing) {
            $this->events?->dispatch(new RenditionsWritten($profile->name, $name, []));

            return [];
        }

        $originals = $this->originals($profile);
        if (!$originals->fileExists($profile->originalPath($name))) {
            $this->events?->dispatch(new RenditionsFailed($profile->name, $name, FailureReasonEnum::Missing));

            throw new UnreadableImageException(FailureReasonEnum::Missing, $profile->originalPath($name));
        }
        $bytes = $originals->read($profile->originalPath($name));
        try {
            if (null === $format) {
                return $this->writeSvg($profile, $name, $bytes);
            }

            return $this->writeRenditions($profile, $name, $format, $specs, $this->engine->draw($profile, $bytes, $specs));
        } catch (UnreadableImageException $exception) {
            $this->events?->dispatch(new RenditionsFailed($profile->name, $name, FailureReasonEnum::Unreadable));

            throw $exception;
        }
    }

    public function delete(string $profile, string $name): void
    {
        $profile = $this->profiles->get($profile);
        $format = self::validate($name);
        $this->originals($profile)->delete($profile->originalPath($name));
        $renditions = $this->renditionsOf($profile);
        if (null === $format) {
            $renditions->delete($profile->svgPath($name));

            return;
        }
        foreach ($profile->renditions() as $spec) {
            $renditions->delete($profile->path($spec, $name, $format));
        }
    }

    private function storeSvg(Profile $profile, string $name, string $bytes): StoredImage
    {
        if (SvgModeEnum::Sanitise !== $profile->svg || !str_ends_with($name, '.svg')) {
            throw new UnreadableImageException(FailureReasonEnum::Unreadable, $name);
        }
        $clean = $this->svgCleaner->clean($bytes) ?? throw new UnreadableImageException(FailureReasonEnum::Unreadable, $name);
        $this->originals($profile)->write($profile->originalPath($name), $bytes);
        $this->writeSvg($profile, $name, $bytes);
        [$width, $height] = $this->svgCleaner->dimensions($clean);

        return new StoredImage($name, self::SVG, \strlen($bytes), $width, $height, hash('sha256', $bytes), null);
    }

    /**
     * @return list<string>
     */
    private function writeSvg(Profile $profile, string $name, string $bytes): array
    {
        $clean = $this->svgCleaner->clean($bytes) ?? throw new UnreadableImageException(FailureReasonEnum::Unreadable, $profile->originalPath($name));
        $path = $profile->svgPath($name);
        $this->renditionsOf($profile)->write($path, $clean, ['CacheControl' => $profile->cacheControl, 'ContentType' => self::SVG]);
        $this->events?->dispatch(new RenditionsWritten($profile->name, $name, [$path]));

        return [$path];
    }

    /**
     * @param list<RenditionSpec> $specs
     * @param list<string>        $drawn
     *
     * @return list<string>
     */
    private function writeRenditions(Profile $profile, string $name, FormatEnum $original, array $specs, array $drawn): array
    {
        $renditions = $this->renditionsOf($profile);
        $paths = [];
        foreach ($specs as $index => $spec) {
            $path = $profile->path($spec, $name, $original);
            $renditions->write($path, $drawn[$index], ['CacheControl' => $profile->cacheControl, 'ContentType' => $spec->formatFor($original)->mimeType()]);
            $paths[] = $path;
        }
        $this->events?->dispatch(new RenditionsWritten($profile->name, $name, $paths));

        return $paths;
    }

    /**
     * The format a stored name's renditions follow in "format: original"; null for an SVG.
     *
     * @throws InvalidNameException
     */
    private static function validate(string $name): ?FormatEnum
    {
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*\.(jpe?g|png|gif|webp|avif|svg)$/i', $name) || str_contains($name, '..')) {
            throw InvalidNameException::name($name);
        }

        return FormatEnum::fromExtension(pathinfo($name, \PATHINFO_EXTENSION));
    }

    private function originals(Profile $profile): FilesystemOperator
    {
        return $this->storage($profile->originals);
    }

    private function renditionsOf(Profile $profile): FilesystemOperator
    {
        return $this->storage($profile->renditions);
    }

    private function storage(string $id): FilesystemOperator
    {
        $storage = $this->storages->get($id);
        if (!$storage instanceof FilesystemOperator) {
            throw new \LogicException(\sprintf('The service "%s" is not a League\Flysystem\FilesystemOperator.', $id));
        }

        return $storage;
    }
}
