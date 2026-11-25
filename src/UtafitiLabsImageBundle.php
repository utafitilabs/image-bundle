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

namespace UtafitiLabs\ImageBundle;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Image renditions: named profiles turn one kept original into fixed renditions on two Flysystem storages.
 *
 * Configuration and services are declared on the bundle class, as "Bundles extending the AbstractBundle class … can
 * define the configuration, load services and prepend config" in one place, and "the $config variable is already
 * merged and processed so you can use it directly" (https://symfony.com/doc/current/bundles/configuration.html). Named
 * profiles are a prototyped array keyed by name, as useAttributeAsKey('name')->arrayPrototype() builds them
 * (https://symfony.com/doc/current/components/config/definition.html#array-node-options). The checks that span several
 * keys of a profile run in one validate() closure on the profiles node, which runs after every profile's own keys are
 * finalised (vendor/symfony/config/Definition/BaseNode.php, finalize()).
 */
final class UtafitiLabsImageBundle extends AbstractBundle
{
    protected string $extensionAlias = 'utafitilabs_image';

    private const array TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml'];
    private const array CLEAR_TYPES = ['image/png', 'image/webp', 'image/avif', 'image/gif'];
    private const array FORMATS = ['jpeg', 'png', 'webp', 'avif'];
    private const array PLACEHOLDERS = ['{name}', '{stem}', '{size}', '{ext}'];
    private const array PROFILE_KEYS = [
        'folder', 'originals', 'renditions', 'accept', 'max_bytes', 'max_pixels', 'min_width', 'min_height', 'min_side',
        'max_side', 'require_clear_edge', 'orient', 'trim', 'fit', 'aspect', 'upscale', 'sizes', 'format', 'quality',
        'interlace', 'background', 'fallback', 'svg', 'path', 'reuse_original_if_fits', 'cache_control', 'generate',
    ];

    public function configure(DefinitionConfigurator $definition): void
    {
        /** @var ArrayNodeDefinition $root */
        $root = $definition->rootNode();
        $children = $root->children();

        $children->enumNode('driver')
            ->info('The image library behind every profile: gd (ext-gd) or imagick (ext-imagick).')
            ->values(['gd', 'imagick'])
            ->defaultValue('gd')
            ->validate()
            ->ifTrue(static fn (string $driver): bool => !\extension_loaded($driver))
            ->then(static fn (string $driver): never => throw new \InvalidArgumentException(\sprintf('The %1$s driver needs the PHP extension "%1$s", which is not loaded.', $driver)));

        $storages = $children->arrayNode('storages')
            ->info('The Flysystem storages, as service ids of League\Flysystem\FilesystemOperator.')
            ->isRequired()
            ->children();
        $storages->scalarNode('originals')->info('Where originals are kept, unchanged and private.')->isRequired()->cannotBeEmpty();
        $storages->scalarNode('renditions')->info('Where renditions are written; its public_url gives their addresses.')->isRequired()->cannotBeEmpty();

        $profiles = $children->arrayNode('profiles')
            ->info('One profile per kind of picture; the name is the application\'s word.')
            ->useAttributeAsKey('name');
        $profiles->validate()->always(self::validateProfiles(...));
        $this->profile($profiles->arrayPrototype()->children());
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{driver: string, storages: array{originals: string, renditions: string}, profiles: array<string, array<string, mixed>>} $config */
        $profiles = [];
        foreach ($config['profiles'] as $name => $profile) {
            $profile['originals'] ??= $config['storages']['originals'];
            $profile['renditions'] ??= $config['storages']['renditions'];
            $profiles[$name] = $profile;
        }
        $container->parameters()->set('utafitilabs_image.profiles', $profiles);
    }

    private function profile(NodeBuilder $profile): void
    {
        $profile->scalarNode('folder')->info('The prefix in both storages; the profile name when not set.')->defaultNull();
        $profile->scalarNode('originals')->info('The originals storage for this profile; the global one when not set.')->defaultNull();
        $profile->scalarNode('renditions')->info('The renditions storage for this profile; the global one when not set.')->defaultNull();
        $profile->arrayNode('accept')
            ->info('The types an upload may be, read from its content.')
            ->performNoDeepMerging()
            ->defaultValue(['image/jpeg', 'image/png'])
            ->enumPrototype()->values(self::TYPES);
        $profile->scalarNode('max_bytes')->info('The upload size limit, in bytes or with K, M or G after the number.')->defaultNull();
        $profile->integerNode('max_pixels')->info('The width × height limit, which bounds the memory a decoded upload takes.')->min(1)->defaultNull();
        foreach (['min_width', 'min_height', 'min_side', 'max_side'] as $limit) {
            $profile->integerNode($limit)->info('A dimension check in pixels, on the stored (unturned) pixels.')->min(1)->defaultNull();
        }
        $profile->booleanNode('require_clear_edge')->info('Refuse an upload with no fully transparent pixel on its edge.')->defaultFalse();
        $profile->booleanNode('orient')->info('Apply the EXIF orientation to every rendition.')->defaultTrue();
        $profile->enumNode('trim')->info('clear: cut the rows and columns that are fully transparent before fitting.')->values(['none', 'clear'])->defaultValue('none');
        $profile->enumNode('fit')
            ->info('What a size is: the width, the height, the longest side (contain) or the width of a crop to aspect (cover).')
            ->values(['width', 'height', 'contain', 'cover'])
            ->defaultValue('width');
        $profile->scalarNode('aspect')->info('The aspect of the crop from the middle, W:H, for fit: cover.')->defaultNull();
        $profile->booleanNode('upscale')->info('Renditions are never larger than the original unless this is set.')->defaultFalse();

        $sizes = $profile->arrayNode('sizes')
            ->info('The renditions, a list of sizes or a map of size to {path, format, quality}.')
            ->isRequired()
            ->performNoDeepMerging()
            ->useAttributeAsKey('size');
        $sizes->beforeNormalization()
            ->ifTrue(static fn (mixed $sizes): bool => \is_array($sizes) && array_is_list($sizes))
            ->then(static function (array $sizes): array {
                $map = [];
                foreach ($sizes as $size) {
                    if (\is_int($size) || \is_string($size)) {
                        $map[$size] = null;
                    }
                }

                return $map;
            });
        $size = $sizes->arrayPrototype()->children();
        $size->scalarNode('path')->defaultNull();
        $size->enumNode('format')->values(self::FORMATS)->defaultNull();
        $size->integerNode('quality')->min(0)->max(100)->defaultNull();

        $profile->enumNode('format')->info('The encoding of the renditions; original keeps the upload\'s own.')->values([...self::FORMATS, 'original'])->defaultValue('original');
        $profile->integerNode('quality')->info('Lossy quality; jpeg 82, webp 74, avif 52 when not set. PNG is always compression 9.')->min(0)->max(100)->defaultNull();
        $profile->booleanNode('interlace')->info('Progressive JPEG.')->defaultFalse();
        $profile->scalarNode('background')->info('Flatten transparency onto this colour, #RGB or #RRGGBB.')->defaultNull();

        $fallback = $profile->arrayNode('fallback')
            ->info('One extra rendition for browsers without the main format.')
            ->performNoDeepMerging()
            ->children();
        $fallback->integerNode('size')->isRequired()->min(1);
        $fallback->enumNode('format')->isRequired()->values(self::FORMATS);
        $fallback->integerNode('quality')->min(0)->max(100)->defaultNull();
        $fallback->booleanNode('interlace')->defaultFalse();

        $profile->enumNode('svg')->info('sanitise: an SVG is accepted and written once, cleaned, for every size.')->values(['refuse', 'sanitise'])->defaultValue('refuse');
        $profile->scalarNode('path')->info('The rendition\'s name in the folder, from {name}, {stem}, {size} and {ext}.')->defaultValue('{stem}-{size}.{ext}');
        $profile->booleanNode('reuse_original_if_fits')->info('A size not smaller than an original of the same format copies its bytes.')->defaultFalse();
        $profile->scalarNode('cache_control')->info('The Cache-Control written with each rendition.')->defaultValue('public, max-age=31536000, immutable')->cannotBeEmpty();
        $profile->enumNode('generate')->info('sync: made in the storing call; async: by dispatching MakeRenditions.')->values(['sync', 'async'])->defaultValue('sync');
    }

    /**
     * @param array<string, array<string, mixed>> $profiles
     *
     * @return array<string, array<string, mixed>>
     */
    private static function validateProfiles(array $profiles): array
    {
        foreach ($profiles as $name => $profile) {
            $profiles[$name] = self::validateProfile((string) $name, $profile);
        }

        return $profiles;
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    private static function validateProfile(string $name, array $profile): array
    {
        $fail = static fn (string $message): never => throw new \InvalidArgumentException(\sprintf('Profile "%s": %s', $name, $message));
        if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
            $fail('a profile name is letters, digits, "_" and "-".');
        }

        $profile['folder'] ??= $name;
        $profile['fallback'] ??= null;
        if (!\is_string($profile['folder']) || 1 !== preg_match('~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$~', $profile['folder']) || \in_array('..', explode('/', $profile['folder']), true)) {
            $fail('"folder" is one or more names of letters, digits, "_", "-" and "." separated by "/", without "..".');
        }

        $profile['max_bytes'] = self::bytes($profile['max_bytes'], $fail);

        /** @var array<int|string, array{path: ?string, format: ?string, quality: ?int}> $sizes */
        $sizes = $profile['sizes'];
        if ([] === $sizes) {
            $fail('"sizes" names at least one size.');
        }
        foreach ($sizes as $size => $override) {
            if (!\is_int($size) || $size < 1) {
                $fail(\sprintf('every size is a whole number of pixels above 0, "%s" is not.', $size));
            }
            $sizes[$size] = ['path' => $override['path'], 'format' => $override['format'], 'quality' => $override['quality']];
        }
        ksort($sizes);
        $profile['sizes'] = $sizes;

        if ('cover' === $profile['fit'] && null === $profile['aspect']) {
            $fail('"fit: cover" needs an "aspect" such as 4:5.');
        }
        if ('cover' !== $profile['fit'] && null !== $profile['aspect']) {
            $fail('"aspect" applies to "fit: cover" only.');
        }
        if (null !== $profile['aspect'] && (!\is_string($profile['aspect']) || 1 !== preg_match('/^[1-9]\d*:[1-9]\d*$/', $profile['aspect']))) {
            $fail(\sprintf('"aspect" is two whole numbers above 0 as W:H, such as 4:5; "%s" is not.', \is_scalar($profile['aspect']) ? $profile['aspect'] : get_debug_type($profile['aspect'])));
        }

        if (null !== $profile['background']) {
            $background = \is_string($profile['background']) ? $profile['background'] : '';
            if (1 !== preg_match('/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $background)) {
                $fail(\sprintf('"background" is a colour as #RGB or #RRGGBB; "%s" is not.', $background));
            }
            $hex = substr($background, 1);
            $profile['background'] = '#'.strtoupper(3 === \strlen($hex) ? $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2] : $hex);
        }

        /** @var list<string> $accept */
        $accept = $profile['accept'];
        /** @var ?array{size: int, format: string, quality: ?int, interlace: bool} $fallback */
        $fallback = $profile['fallback'];
        $outputs = [$profile['format'], ...array_column($sizes, 'format'), $fallback['format'] ?? null];
        $clear = array_values(array_intersect(self::CLEAR_TYPES, $accept));
        if (\in_array('jpeg', $outputs, true) && [] !== $clear && null === $profile['background']) {
            $fail(\sprintf('a JPEG has no transparency, so a profile that accepts %s and writes JPEG needs a "background".', $clear[0]));
        }

        if (\in_array('image/svg+xml', $accept, true) && 'sanitise' !== $profile['svg']) {
            $fail('it accepts image/svg+xml, so it needs "svg: sanitise".');
        }
        if ('sanitise' === $profile['svg'] && !\in_array('image/svg+xml', $accept, true)) {
            $fail('"svg: sanitise" needs image/svg+xml in "accept".');
        }

        /** @var string $template */
        $template = $profile['path'];
        $rendered = [];
        $renditions = [];
        /** @var string $format */
        $format = $profile['format'];
        foreach ($sizes as $size => $override) {
            $renditions[] = [$override['path'] ?? $template, $size, $override['format'] ?? $format];
        }
        if (null !== $fallback) {
            $renditions[] = [$template, $fallback['size'], $fallback['format']];
        }
        foreach ($renditions as [$path, $size, $format]) {
            preg_match_all('/\{[^}]*\}/', $path, $found);
            foreach ($found[0] as $placeholder) {
                if (!\in_array($placeholder, self::PLACEHOLDERS, true)) {
                    $fail(\sprintf('"path" knows {name}, {stem}, {size} and {ext}; "%s" is not one of them.', $placeholder));
                }
            }
            if ('' === $path || str_starts_with($path, '/') || str_contains($path, '..')) {
                $fail('"path" stays inside the folder: no "..", no leading "/".');
            }
            $sample = strtr($path, ['{name}' => 'x.source', '{stem}' => 'x', '{size}' => (string) $size, '{ext}' => $format]);
            if (isset($rendered[$sample])) {
                $fail(\sprintf('two renditions would be written to the same path "%s".', $path));
            }
            $rendered[$sample] = true;
        }

        /** @var ?int $maxSide */
        $maxSide = $profile['max_side'];
        foreach (['min_side', 'min_width', 'min_height'] as $minimum) {
            /** @var ?int $least */
            $least = $profile[$minimum];
            if (null !== $least && null !== $maxSide && $least > $maxSide) {
                $fail(\sprintf('"%s" (%d) is over "max_side" (%d).', $minimum, $least, $maxSide));
            }
        }

        if (true === $profile['reuse_original_if_fits'] && ('cover' === $profile['fit'] || 'clear' === $profile['trim'])) {
            $fail('"reuse_original_if_fits" cannot be used with "fit: cover" or "trim: clear", which always change the pixels.');
        }

        $ordered = [];
        foreach (self::PROFILE_KEYS as $key) {
            $ordered[$key] = $profile[$key];
        }

        return $ordered;
    }

    /**
     * @param callable(string): never $fail
     */
    private static function bytes(mixed $limit, callable $fail): ?int
    {
        if (null === $limit || \is_int($limit) && $limit > 0) {
            return $limit;
        }
        if (\is_string($limit) && 1 === preg_match('/^([1-9]\d*)([KMG]?)$/i', $limit, $match)) {
            return (int) $match[1] * match (strtoupper($match[2])) {
                'K' => 1024,
                'M' => 1024 ** 2,
                'G' => 1024 ** 3,
                default => 1,
            };
        }
        $fail(\sprintf('"max_bytes" is a number of bytes, or one with K, M or G after it; "%s" is not.', \is_scalar($limit) ? $limit : get_debug_type($limit)));
    }
}
