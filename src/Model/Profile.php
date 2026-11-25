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
 * One named profile as configured: where its files go, what an upload must be, and the renditions it makes.
 */
final readonly class Profile
{
    /**
     * @param list<string>        $accept
     * @param ?array{int, int}    $aspect
     * @param list<RenditionSpec> $sizes
     */
    public function __construct(
        public string $name,
        public string $folder,
        public string $originals,
        public string $renditions,
        public array $accept,
        public ?int $maxBytes,
        public ?int $maxPixels,
        public ?int $minWidth,
        public ?int $minHeight,
        public ?int $minSide,
        public ?int $maxSide,
        public bool $requireClearEdge,
        public bool $orient,
        public TrimEnum $trim,
        public FitEnum $fit,
        public ?array $aspect,
        public bool $upscale,
        public array $sizes,
        public ?RenditionSpec $fallback,
        public ?string $background,
        public SvgModeEnum $svg,
        public bool $reuseOriginalIfFits,
        public string $cacheControl,
        public GenerateEnum $generate,
    ) {
    }

    /**
     * @param array<string, mixed> $config one profile of the processed utafitilabs_image.profiles parameter
     */
    public static function fromConfig(string $name, array $config): self
    {
        /** @var array{folder: string, originals: string, renditions: string, accept: list<string>, max_bytes: ?int, max_pixels: ?int, min_width: ?int, min_height: ?int, min_side: ?int, max_side: ?int, require_clear_edge: bool, orient: bool, trim: string, fit: string, aspect: ?string, upscale: bool, sizes: array<int, array{path: ?string, format: ?string, quality: ?int}>, format: string, quality: ?int, interlace: bool, background: ?string, fallback: ?array{size: int, format: string, quality: ?int, interlace: bool}, svg: string, path: string, reuse_original_if_fits: bool, cache_control: string, generate: string} $config */
        $format = 'original' === $config['format'] ? null : FormatEnum::from($config['format']);
        $sizes = [];
        foreach ($config['sizes'] as $size => $override) {
            $sizes[] = new RenditionSpec(
                $size,
                $override['path'] ?? $config['path'],
                null === $override['format'] ? $format : FormatEnum::from($override['format']),
                $override['quality'] ?? $config['quality'],
                $config['interlace'],
            );
        }
        $fallback = null === $config['fallback'] ? null : new RenditionSpec(
            $config['fallback']['size'],
            $config['path'],
            FormatEnum::from($config['fallback']['format']),
            $config['fallback']['quality'],
            $config['fallback']['interlace'],
            true,
        );
        $aspect = null === $config['aspect'] ? null : array_map(intval(...), explode(':', $config['aspect'], 2));

        return new self(
            $name,
            $config['folder'],
            $config['originals'],
            $config['renditions'],
            $config['accept'],
            $config['max_bytes'],
            $config['max_pixels'],
            $config['min_width'],
            $config['min_height'],
            $config['min_side'],
            $config['max_side'],
            $config['require_clear_edge'],
            $config['orient'],
            TrimEnum::from($config['trim']),
            FitEnum::from($config['fit']),
            null === $aspect ? null : [$aspect[0], $aspect[1] ?? 1],
            $config['upscale'],
            $sizes,
            $fallback,
            $config['background'],
            SvgModeEnum::from($config['svg']),
            $config['reuse_original_if_fits'],
            $config['cache_control'],
            GenerateEnum::from($config['generate']),
        );
    }

    /**
     * Every rendition: the sizes, smallest first, then the fallback.
     *
     * @return list<RenditionSpec>
     */
    public function renditions(): array
    {
        return null === $this->fallback ? $this->sizes : [...$this->sizes, $this->fallback];
    }

    /** @return list<int> */
    public function sizeList(): array
    {
        return array_map(static fn (RenditionSpec $spec): int => $spec->size, $this->sizes);
    }

    public function size(int $size): ?RenditionSpec
    {
        foreach ($this->sizes as $spec) {
            if ($spec->size === $size) {
                return $spec;
            }
        }

        return null;
    }

    public function accepts(string $mimeType): bool
    {
        return \in_array($mimeType, $this->accept, true);
    }

    public function originalPath(string $name): string
    {
        return $this->folder.'/'.$name;
    }

    /** The one cleaned copy a sanitised SVG has, for every size. */
    public function svgPath(string $name): string
    {
        return $this->folder.'/'.pathinfo($name, \PATHINFO_FILENAME).'.svg';
    }

    /** Where a rendition of the stored name is written, for an original of the given format. */
    public function path(RenditionSpec $spec, string $name, FormatEnum $original): string
    {
        return $this->folder.'/'.strtr($spec->path, [
            '{name}' => $name,
            '{stem}' => pathinfo($name, \PATHINFO_FILENAME),
            '{size}' => (string) $spec->size,
            '{ext}' => $spec->formatFor($original)->extension(),
        ]);
    }
}
