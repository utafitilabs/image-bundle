# Changelog

All notable changes to `utafitilabs/image-bundle` are documented here.

## Contents

- [v0.1.0 — unreleased](#v010--unreleased)

## v0.1.0 — unreleased

### Added

- Named profiles under `utafitilabs_image.profiles`, validated when the container is
  compiled, each with a clear message naming the profile and the key.
- Renditions drawn on `imagine/imagine` with the `gd` driver (default) or `imagick`: fit by
  `width`, `height`, `contain` (the longest side) or `cover` (a crop to `aspect` from the
  middle), never larger than the original unless `upscale` is set; JPEG, PNG, WebP and
  AVIF (refused with a clear message when the driver cannot write it); `quality`,
  progressive JPEG with `interlace`, PNG at compression 9.
- Transparency flattened onto `background`; a profile that accepts a type with
  transparency and writes JPEG must name one, so a clear pixel never comes out black.
- The EXIF orientation applied (`orient`), and no metadata copied into a rendition.
- `trim: clear`: the rows and columns whose every pixel is fully transparent are cut
  before fitting.
- `svg: sanitise`: an SVG is written once, cleaned, for every size; one that declares an
  external entity is refused.
- `fallback`: one extra rendition for browsers without the main format.
- `reuse_original_if_fits`: a size an original of the same format already fits copies its
  bytes.
- Originals and renditions on the Flysystem storages a profile names, renditions written
  with `CacheControl` and `ContentType`.
- `ImagesInterface` (`utafitilabs_image.images`): `check`, `store`, `storeAs`, `url`,
  `fallbackUrl`, `srcset`, `redraw`, `delete`; `ImageCheckerService`
  (`utafitilabs_image.checker`) returning a `Refusal` with a `RefusalReasonEnum` code.
- `generate: async`: `MakeRenditions` dispatched on the bus and handled by the bundle;
  a redelivered message writes nothing.
- Events `RenditionsWritten` and `RenditionsFailed`.
- `image:renditions <profile> [name…] [--all]`, with the application's
  `ImageNamesInterface` services tagged `utafitilabs_image.names`.
- Twig functions `image_url`, `image_srcset` and `image_fallback`.
