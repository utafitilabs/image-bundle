# UtafitiLabsImageBundle

Fixed image renditions for **Symfony**: named profiles turn one kept original into the
sizes and formats an application shows, made ahead of time on
[imagine/imagine](https://imagine.readthedocs.io/) and written to
[Flysystem](https://flysystem.thephpleague.com/) storages.

## Contents

- [Installation](#installation)
- [Requirements](#requirements)
- [A full configuration](#a-full-configuration)
- [Profile reference](#profile-reference)
- [Names and paths](#names-and-paths)
- [Fits and sizes](#fits-and-sizes)
- [Formats, quality and transparency](#formats-quality-and-transparency)
- [Orientation and metadata](#orientation-and-metadata)
- [Trimming a clear margin](#trimming-a-clear-margin)
- [SVG](#svg)
- [Upload checks](#upload-checks)
- [Making renditions: sync or async](#making-renditions-sync-or-async)
- [The PHP API](#the-php-api)
- [Twig](#twig)
- [The command](#the-command)
- [Events](#events)
- [Exceptions](#exceptions)
- [Storages and caching](#storages-and-caching)
- [Memory](#memory)
- [The imagick driver](#the-imagick-driver)
- [Testing an application](#testing-an-application)
- [What it is not](#what-it-is-not)

## Installation

```console
composer require utafitilabs/image-bundle
```

Without Symfony Flex, enable the bundle:

```php
// config/bundles.php
return [
    // ...
    UtafitiLabs\ImageBundle\UtafitiLabsImageBundle::class => ['all' => true],
];
```

## Requirements

- PHP 8.4+ with exif, fileinfo and dom, and gd (the default driver) or imagick.
- Symfony 7.3+ or 8.
- Two Flysystem operators, usually from
  [flysystem-bundle](https://github.com/thephpleague/flysystem-bundle): one private for the
  originals, one public for the renditions, with a `public_url`.
- symfony/messenger with a transport for `generate: async` (installed with the bundle;
  the application routes the message).
- twig/twig for the Twig functions (optional).

## A full configuration

An imagined magazine site: author avatars, author cut-outs for its covers, partner logos
that come as PNG or SVG, and a photo library.

```yaml
# config/packages/utafitilabs_image.yaml
utafitilabs_image:
    driver: gd
    storages:
        originals: app.storage.originals
        renditions: app.storage.media

    profiles:
        # An author's face: the middle of the photo cut to 4:5, three widths, a JPEG on white.
        avatar:
            folder: authors
            accept: [image/jpeg, image/png]
            max_bytes: 10M
            min_width: 800
            min_height: 1000
            fit: cover
            aspect: 4:5
            sizes: [320, 800, 1080]
            format: jpeg
            quality: 82
            interlace: true
            background: '#FFFFFF'
            cache_control: 'public, max-age=604800'

        # An author cut out of the background for the covers: a PNG kept clear, by height.
        cutout:
            folder: authors
            accept: [image/png]
            max_bytes: 10M
            min_height: 1000
            max_side: 4000
            require_clear_edge: true
            fit: height
            sizes: [400, 1920]
            format: png
            cache_control: 'public, max-age=604800'

        # A partner's logo: a PNG trimmed of its clear margin and fitted in a square, or an SVG cleaned once.
        logo:
            folder: logos
            accept: [image/png, image/svg+xml]
            min_side: 512
            trim: clear
            fit: contain
            sizes:
                256: { path: '{name}' }   # logos/<stored name>
                512: ~                    # logos/<stem>-512.png
            format: png
            svg: sanitise

        # A library photo: four WebP widths and a JPEG for browsers without WebP, made by the worker.
        photo:
            folder: photos
            accept: [image/jpeg, image/png]
            max_bytes: 20M
            max_pixels: 40000000
            fit: width
            sizes: [320, 800, 1280, 2000]
            format: webp
            quality: 74
            background: '#FFFFFF'
            fallback: { size: 1280, format: jpeg, quality: 72, interlace: true }
            generate: async

when@test:
    utafitilabs_image:
        profiles:
            photo: { generate: sync }
```

A later file or a `when@` block replaces a profile's `sizes`, `accept` and `fallback` as a
whole, and merges its other keys.

## Profile reference

| Key | Type | Default | What it does |
|---|---|---|---|
| `driver` | `gd`, `imagick` | `gd` | Imagine's driver for every profile; the container refuses to compile when its extension is not loaded |
| `storages.originals` | service id | required | where originals are kept, unchanged |
| `storages.renditions` | service id | required | where renditions are written; its `public_url` gives their addresses |
| `profiles.*.folder` | string | the profile's name | the prefix in both storages, `a` or `a/b` |
| `profiles.*.originals`, `.renditions` | service id | the global ones | this profile's own storages |
| `profiles.*.accept` | list of types | `[image/jpeg, image/png]` | among `image/jpeg`, `image/png`, `image/webp`, `image/avif`, `image/gif`, `image/svg+xml`; read from the content |
| `profiles.*.max_bytes` | int, or a number with `K`, `M`, `G` | none | the upload size limit (binary units: `10M` is 10 × 1024 × 1024) |
| `profiles.*.max_pixels` | int | none | width × height, which bounds the memory a decoded picture takes |
| `profiles.*.min_width`, `min_height`, `min_side`, `max_side` | int | none | dimensions, on the stored pixels before the EXIF orientation |
| `profiles.*.require_clear_edge` | bool | `false` | refuse a picture with no fully transparent pixel on its edge |
| `profiles.*.orient` | bool | `true` | apply the EXIF orientation to every rendition |
| `profiles.*.trim` | `none`, `clear` | `none` | cut the fully transparent margin before fitting |
| `profiles.*.fit` | `width`, `height`, `contain`, `cover` | `width` | what a size is |
| `profiles.*.aspect` | `W:H` | none | the crop's aspect; required by `cover` and only allowed with it |
| `profiles.*.upscale` | bool | `false` | allow renditions larger than the original |
| `profiles.*.sizes` | list of int, or map of int to `{path, format, quality}` | required | the renditions; the map form overrides one size |
| `profiles.*.format` | `jpeg`, `png`, `webp`, `avif`, `original` | `original` | the renditions' encoding |
| `profiles.*.quality` | 0–100 | jpeg 82, webp 74, avif 52 | lossy quality; PNG is always compression 9 |
| `profiles.*.interlace` | bool | `false` | progressive JPEG |
| `profiles.*.background` | `#RGB`, `#RRGGBB` | none | flatten transparency onto this colour |
| `profiles.*.fallback` | `{size, format, quality, interlace}` | none | one more rendition for browsers without the main format |
| `profiles.*.svg` | `refuse`, `sanitise` | `refuse` | accept SVG and write it once, cleaned |
| `profiles.*.path` | pattern | `{stem}-{size}.{ext}` | a rendition's name in the folder |
| `profiles.*.reuse_original_if_fits` | bool | `false` | copy the original's bytes for a size it already fits |
| `profiles.*.cache_control` | string | `public, max-age=31536000, immutable` | written with each rendition |
| `profiles.*.generate` | `sync`, `async` | `sync` | make renditions in the storing call, or dispatch `MakeRenditions` |

A profile is checked when the container compiles. Every message names the profile:
`cover` without `aspect`, an `aspect` that is not `W:H`, no sizes or a size below 1, an
unknown placeholder in `path`, a `path` or `folder` that leaves the folder, two renditions
on one path, a minimum over `max_side`, SVG accepted without `svg: sanitise` (or the
reverse), `reuse_original_if_fits` with `cover` or `trim`, and a JPEG output from a profile
that accepts a type with transparency but names no `background`.

## Names and paths

`store()` names an original `<label>-<16 hex>.<ext>`, or `<32 hex>.<ext>` without a label;
the extension follows the content (`jpg`, `png`, `webp`, `avif`, `gif`, `svg`), never the
uploaded file's name. A label is letters, digits, `_` and `-`. `storeAs()` takes a name the
application chose, which must have one of those extensions and stay inside the folder.

The original is kept at `<folder>/<name>`. A rendition goes to `<folder>/<path>`, where
`path` knows `{name}` (the stored name), `{stem}` (without its extension), `{size}` (the
size asked for, even when the original was smaller) and `{ext}` (the rendition's format).
The same name always gives the same paths, so addresses can be printed without asking the
storage.

## Fits and sizes

- `width`: the size is the width; the height follows.
- `height`: the size is the height; the width follows.
- `contain`: the size is the longest side.
- `cover`: the largest box of `aspect` is cut from the middle, then the size is its width.

A rendition is never larger than the original (for `cover`, than the crop) unless
`upscale` is set; it keeps the name of the size asked for.

## Formats, quality and transparency

`format: original` keeps each original's format (a GIF becomes a PNG). `sizes` in the map
form may give one size its own `format` and `quality`. A format the driver cannot write
(AVIF on a library built without it) is refused with `UnsupportedFormatException` naming
the driver and the format.

PNG and WebP keep transparency. With `background` set, every rendition of the profile is
flattened onto that colour; a JPEG from a source that may have transparency needs one,
which the configuration enforces.

## Orientation and metadata

With `orient: true` the EXIF orientation (1 to 8) is applied to every rendition, so a
phone photo taken upright comes out upright. No rendition carries the original's EXIF
(with where it was taken), XMP or comments: GD keeps pixels alone, and Imagick's profiles
are stripped. `StoredImage` gives the width and height as shown and `takenAt` from
`DateTimeOriginal`. The dimension checks read the stored pixels, before turning.

`reuse_original_if_fits` copies the original's bytes, metadata included, for a size it
already fits in the same format, without turning; use it only where that is acceptable.

## Trimming a clear margin

`trim: clear` cuts the rows and columns whose every pixel is fully transparent, then fits
what is left. A picture with no clear margin, an opaque rectangle and a fully clear
picture are left as they are; a single opaque pixel becomes a 1 × 1 picture. A pixel that
is almost clear counts as part of the figure.

## SVG

With `svg: sanitise` and `image/svg+xml` in `accept`, an SVG is written once,
`<folder>/<stem>.svg`, and `url()`, `fallbackUrl()` and `srcset()` answer that copy for
every size. The copy keeps the SVG namespace's elements and the attributes without a
namespace (and XLink's and XML's). It drops comments, processing instructions, `metadata`,
`script`, `foreignObject`, other namespaces (editor data), every `on*` handler, and every
`href` other than `#id` or an embedded PNG, JPEG, GIF or WebP. A document type that
declares an external entity is refused; internal entities are resolved. libxml never
fetches anything and its own limit stops an entity expansion bomb. It is an allow-list by
namespace, not a CSS parser: `style` is kept as it is. The original is kept unchanged in
the originals storage.

## Upload checks

```php
$refusal = $images->check('photo', $uploadedFile);   // null, or a Refusal
```

The checks are those a profile declares, in this order: the upload arrived whole (PHP's
upload errors; its size limits answer `bytes`), `max_bytes`, the type read from the content
(fileinfo, and for a raster the header must agree), `max_pixels` from the header without
decoding, the dimensions, `require_clear_edge` (decodes the picture), and for SVG that it
can be cleaned. A `Refusal` carries a `RefusalReasonEnum` and the profile's `limit`:

| Code | Meaning | `limit` |
|---|---|---|
| `not_arrived` | the upload did not arrive whole | — |
| `type` | not a type the profile accepts | — |
| `unreadable` | claims an accepted type but cannot be read as one | — |
| `bytes` | over `max_bytes` | bytes |
| `pixels` | over `max_pixels` | pixels |
| `min_width`, `min_height`, `min_side`, `max_side` | outside that dimension | pixels |
| `clear_edge` | no fully transparent pixel on its edge | — |

The bundle returns codes; the words a person reads, and their translations, are the
application's.

## Making renditions: sync or async

`generate: sync` draws every rendition in memory first, then writes the original and the
renditions, so an original that cannot be drawn leaves nothing behind. `generate: async`
writes the original and dispatches `MakeRenditions(profile, name)`; route it:

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        transports:
            async: '%env(MESSENGER_TRANSPORT_DSN)%'
        routing:
            UtafitiLabs\ImageBundle\Message\MakeRenditions: async
```

The bundle's handler writes only what is missing, so a redelivered message writes
nothing. A missing or unreadable original ends in `RenditionsFailed` and is not retried; a
storage error is thrown, so Messenger retries it.

## The PHP API

`UtafitiLabs\ImageBundle\Service\ImagesInterface`, service `utafitilabs_image.images`:

| Method | Returns |
|---|---|
| `check(string $profile, File $file)` | `?Refusal` |
| `store(string $profile, File\|string $source, ?string $label = null)` | `StoredImage` (a string is a local path) |
| `storeAs(string $profile, string $name, string $bytes)` | `StoredImage` |
| `url(string $profile, string $name, int $size)` | the rendition's public address |
| `fallbackUrl(string $profile, string $name)` | the fallback's public address |
| `srcset(string $profile, string $name, ?int $originalWidth = null)` | `"…-320.webp 320w, …"` |
| `redraw(string $profile, string $name, bool $all = false)` | the paths written |
| `delete(string $profile, string $name)` | the original and every rendition go |

`StoredImage` has `name`, `mimeType`, `bytes`, `width` and `height` as shown (null for an
SVG that states none), `sha256` and `takenAt`.

`srcset()` works for profiles whose sizes are widths (`width`, `cover`). Given the
original's width, each size is described no wider than it, and a width already described
is left out, so a narrow photo never repeats a descriptor.

`ImageCheckerService` (`utafitilabs_image.checker`) is the checker on its own.

## Twig

```twig
{{ image_url('avatar', author.picture, 320) }}
{{ image_srcset('photo', photo.name, photo.width) }}
{{ image_fallback('photo', photo.name) }}
```

`<picture>`, `sizes`, `loading` and `object-position` stay in the application's
templates.

## The command

```console
php bin/console image:renditions <profile> [name…] [--all] [-v]
```

It writes what is missing, or everything with `--all`, from the originals, and ends with a
count; a name whose original is missing or unreadable is reported and makes the command
fail. Without names it asks the application, through a service implementing
`ImageNamesInterface` tagged for the profile:

```php
// config/services.php
$services->set('app.author_picture_names', AuthorPictureNames::class)
    ->args([service('doctrine.orm.entity_manager')])
    ->tag('utafitilabs_image.names', ['profile' => 'avatar'])
    ->tag('utafitilabs_image.names', ['profile' => 'cutout']);
```

A profile without one is listed from its folder in the originals storage, which is only
right when no other profile shares that folder.

## Events

- `RenditionsWritten(profile, name, paths)`: every rendition is in place; `paths` are
  those written this time, empty when nothing was missing.
- `RenditionsFailed(profile, name, reason)`: `FailureReasonEnum::Missing` (deleted before
  the worker reached it, for one) or `Unreadable`.

Both come from every drawing: `store()` with `sync`, the handler, `redraw()` and the
command.

## Exceptions

All implement `ImageExceptionInterface`: `UnknownProfileException`,
`UnknownSizeException` (no such size, no fallback, no `srcset` for these sizes),
`InvalidNameException`, `UnreadableImageException` (with its `FailureReasonEnum`) and
`UnsupportedFormatException`.

## Storages and caching

Each rendition is written with `CacheControl` (the profile's `cache_control`) and
`ContentType`; S3 adapters store both on the object, a local adapter ignores them. Names
never change for a stored original, so `immutable` is safe. Make the renditions storage
public and the originals storage private in Flysystem's own configuration.

## Memory

A picture is decoded once. The renditions are made largest first, each scaled down from
the one before it, so the decoded original is never copied per size. GD holds about
4 bytes a pixel: a 40-megapixel photo takes about 160 MB, which `max_pixels` bounds before
anything is decoded.

## The imagick driver

`driver: imagick` uses ImageMagick through ext-imagick, which can read HEIC where
ImageMagick is built with libheif, and AVIF where built with it. The same tests run
against both drivers.

## Testing an application

Set `generate: sync` for asynchronous profiles in `when@test`, or route `MakeRenditions`
to an `in-memory://` transport and hand the message to the bus with a `ReceivedStamp`.
Point both storages at flysystem-bundle's `memory` adapter.

## What it is not

Not a media library (no entity, tables, folders, captions or duplicate detection), not an
upload screen, and not an on-request image server: a new size goes into `sizes` and
`image:renditions <profile> --all` writes it.
