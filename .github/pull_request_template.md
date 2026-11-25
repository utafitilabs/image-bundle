<!--
CI must be green before this PR can be reviewed. See CONTRIBUTING.md.
-->

## What & why

<!-- Describe the change and the motivation. Link any issue. -->

## Checklist (required)

- [ ] Tests added/updated **first** (TDD) — unit, integration and/or functional
- [ ] `declare(strict_types=1);` in every new PHP file
- [ ] `composer cs:fix` run; `composer check` is green locally
- [ ] Test images drawn by the tests themselves; no binary fixture added
- [ ] No human sentence returned to an application where a reason code belongs
- [ ] Works with both drivers (gd and imagick), or the imagick test says why it skips
