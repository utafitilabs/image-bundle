# Image renditions for Symfony!

[![CI](https://github.com/utafitilabs/image-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/utafitilabs/image-bundle/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/utafitilabs/image-bundle)](https://packagist.org/packages/utafitilabs/image-bundle)
[![Total Downloads](https://img.shields.io/packagist/dt/utafitilabs/image-bundle)](https://packagist.org/packages/utafitilabs/image-bundle)
[![License](https://img.shields.io/packagist/l/utafitilabs/image-bundle)](LICENSE)

This bundle turns **one kept original into fixed renditions**, described by **named
profiles in YAML**: widths, heights or a crop to an aspect, **JPEG, PNG, WebP or AVIF**,
transparency kept or **flattened onto a background**, the **EXIF orientation applied** and
no metadata copied, a **clear margin trimmed**, an **SVG cleaned** before it is served.
Renditions are made **ahead of time**, at once or by a **Messenger** worker, and written to
**Flysystem** storages, so a public page reads files from a bucket and never runs PHP for a
picture. Name a profile, store an upload, print its `srcset`.

> Part of the [utafiti tools](https://github.com/utafitilabs).

## Contents

- [Why](#why)
- [Install](#install)
- [A taste](#a-taste)
- [Documentation](#documentation)
- [Requirements](#requirements)
- [Contributing](#contributing)
- [Credits](#credits)
- [License](#license)

## Why

Every application that takes pictures ends up writing the same few hundred lines: decode,
turn, crop, scale, encode, write three sizes, delete them again, answer their addresses,
and check the upload before any of it. The usual bundles make images on request behind a
resolve route, which puts PHP in front of every new size and ties addresses to their
cache layout. This bundle keeps the original private, writes every rendition once under
a name you can predict, and leaves your records, screens and words to you.

## Install

Applications using [Symfony Flex](https://symfony.com/doc/current/setup.html):

```console
composer require utafitilabs/image-bundle
```

Applications without Symfony Flex — after requiring the package, enable the bundle:

```php
// config/bundles.php
return [
    // ...
    UtafitiLabs\ImageBundle\UtafitiLabsImageBundle::class => ['all' => true],
];
```

That one line registers the images service (`ImagesInterface`), the upload checker, the
`MakeRenditions` handler, the `image:renditions` command and, when Twig is installed,
`image_url`, `image_srcset` and `image_fallback`. Then name your two storages and a
profile:

```yaml
# config/packages/utafitilabs_image.yaml
utafitilabs_image:
    storages:
        originals: app.storage.originals   # private: kept exactly as uploaded
        renditions: app.storage.media      # public: its public_url gives the addresses
    profiles:
        photo:
            accept: [image/jpeg, image/png]
            max_bytes: 20M
            max_pixels: 40000000
            sizes: [320, 800, 1280, 2000]
            format: webp
            background: '#FFFFFF'
            fallback: { size: 1280, format: jpeg, interlace: true }
            generate: async
```

```yaml
# config/packages/messenger.yaml
framework:
    messenger:
        routing:
            UtafitiLabs\ImageBundle\Message\MakeRenditions: async
```

The storages are Flysystem operators, as
[flysystem-bundle](https://github.com/thephpleague/flysystem-bundle) names them:

```yaml
# config/packages/flysystem.yaml
flysystem:
    storages:
        app.storage.originals:
            adapter: local
            options: { directory: '%kernel.project_dir%/var/originals' }
        app.storage.media:
            adapter: local
            options: { directory: '%kernel.project_dir%/public/media' }
            public_url: '%env(MEDIA_PUBLIC_URL)%'
```

## A taste

```php
// src/Controller/PhotoUploadController.php
namespace App\Controller;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use UtafitiLabs\ImageBundle\Model\RefusalReasonEnum;
use UtafitiLabs\ImageBundle\Service\ImagesInterface;

final readonly class PhotoUploadController
{
    public function __construct(private ImagesInterface $images, private PhotoStore $photos)
    {
    }

    public function upload(UploadedFile $file): string
    {
        // The bundle answers with a code and the limit; the words are yours.
        if (null !== $refusal = $this->images->check('photo', $file)) {
            return match ($refusal->reason) {
                RefusalReasonEnum::Bytes => sprintf('Over %d MB', $refusal->limit / 1024 / 1024),
                RefusalReasonEnum::Type => 'A JPEG or a PNG, please',
                default => 'This picture cannot be used',
            };
        }

        $stored = $this->images->store('photo', $file);   // "3f9c…e1.jpg"; renditions queued
        $this->photos->add($stored->name, $stored->width, $stored->height, $stored->takenAt);

        return $stored->name;
    }
}
```

```twig
{# templates/photo/_picture.html.twig #}
<picture>
    <source type="image/webp" srcset="{{ image_srcset('photo', photo.name, photo.width) }}" sizes="(max-width: 700px) 100vw, 700px">
    <img src="{{ image_fallback('photo', photo.name) }}" alt="{{ photo.caption }}" loading="lazy">
</picture>
```

```console
php bin/console image:renditions photo --all
```

Fits: `width`, `height`, `contain` (the longest side), `cover` (a crop to `aspect` from the
middle). Formats: `jpeg`, `png`, `webp`, `avif`, or `original`. Checks: type by content,
`max_bytes`, `max_pixels`, `min_width`, `min_height`, `min_side`, `max_side`,
`require_clear_edge`. Service: `ImagesInterface` (`check`, `store`, `storeAs`, `url`,
`fallbackUrl`, `srcset`, `redraw`, `delete`). Events: `RenditionsWritten`,
`RenditionsFailed`. Message: `MakeRenditions`. Drivers: `gd` (default) and `imagick`.

## Documentation

Read the documentation at [docs/index.md](docs/index.md) — every profile key, paths and
names, fits, formats and transparency, orientation and metadata, trimming, SVG cleaning
and its limits, upload checks and their codes, synchronous and asynchronous generation,
the command, events, and memory.

## Requirements

- PHP **8.4+** with **exif**, **fileinfo** and **dom**, and **gd** or **imagick**
- Symfony **7.3+ / 8**
- [imagine/imagine](https://github.com/php-imagine/Imagine) **1.5+** and
  [league/flysystem](https://flysystem.thephpleague.com/) **3.10+** (installed with the bundle)
- AVIF needs a GD or ImageMagick built with it (`gd_info()['AVIF Support']`,
  `Imagick::queryFormats('AVIF')`)

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). CI enforces the standard (php-cs-fixer, PHPStan max,
PHPUnit against real GD and Imagick with pictures the tests draw themselves, a booted
kernel with Messenger and Twig, lowest→newest dependency matrix, 95 % coverage) on every
pull request.

## Credits

- [Ezekiel Mjema](https://github.com/eemjema)
- [All Contributors](https://github.com/utafitilabs/image-bundle/graphs/contributors)

## License

MIT License (MIT): see the [LICENSE](LICENSE) file for more details.
