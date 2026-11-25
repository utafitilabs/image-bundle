# Contributing to UtafitiLabsImageBundle

Thanks for your interest! This project holds a high, automated bar so maintainers can
review with confidence. **CI is a gatekeeper: a pull request is only considered for review
once every check below is green.** Please run the gate locally before opening a PR.

## Contents

- [Requirements for every pull request](#requirements-for-every-pull-request)
- [The gate](#the-gate)
- [Scope](#scope)

## Requirements for every pull request

1. **Test-driven.** No production code without a test. Add/extend:
   - `tests/Unit/` for pure logic — the configuration tree and its errors, geometry, the
     SVG cleaner, the EXIF reader, the profile model.
   - `tests/Integration/` for drawing with a real driver (`AbstractRenditionEngineTestCase`
     runs once for GD and once for Imagick, which skips where ext-imagick is missing), the
     upload checks and the images service on in-memory Flysystem storages.
   - `tests/Functional/` for the bundle's wiring in a booted kernel: Messenger, the
     console command, Twig.
2. **Test images are drawn by the tests** (`tests/Double/SyntheticImage`). No binary fixture.
3. **Codes, not sentences.** What an application shows its users is the application's:
   the bundle returns reason codes (`RefusalReasonEnum`, `FailureReasonEnum`).
4. **Both drivers.** A change to drawing works with `gd` and `imagick`.
5. **`declare(strict_types=1);`** in every PHP file; `@Symfony` + `@Symfony:risky`
   (php-cs-fixer); PHPStan at **max**, zero errors.
6. **Synthetic data only** in tests and docs: `example.com`, `example.org`, `example.net`.

## The gate

```bash
composer install
composer cs:fix     # auto-format first
composer check      # cs:check -> phpstan (max) -> tests   (must be green)
```

The same `composer check` runs in CI (GitHub Actions) on every push and pull request,
with ext-gd and ext-imagick, and with a coverage floor of 95 %.

## Scope

`UtafitiLabsImageBundle` makes fixed renditions ahead of time. It is not a media library
(no entities, no upload screen) and not an on-request image server. Bug fixes, drivers'
edge cases, new formats a driver can write, and documentation improvements are all welcome.
