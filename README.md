# mono-build-knowledge

The knowledge of the PrestaHaven factory: the package index, the PrestaShop index per release and the external package search with its compatibility check, so a module reuses what exists before new code is written.

A monorepo of the PrestaHaven package collection: every folder in `packages/` is one Composer package, published as a
read-only repo `prestashopheaven/build-<folder>` on Packagist. All packages of this monorepo share one version.
The plan, the rules and the other monorepos: `docs/packages/` in the PrestaHaven workspace.

## Packages

| Package | Use when |
|---|---|
| `prestashopheaven/build-package-index` | Search our packages before writing code (`ps-module find`); the package index and the `CONTEXT.md` catalog |
| `prestashopheaven/build-prestashop-index` | Does PrestaShop have it, in which releases (`ps-module ps find`); check a module against every release of its range (`ps-module ps check`) |
| `prestashopheaven/build-external` | Search Packagist and npm (`ps-module find --external`); can an external package be used without breaking our modules (`ps-module external check`) |

(`ps-module mono packages` lists them; the package index in the workspace has every class.)

## Work on it

```
composer install
composer check                 # ps-module mono check: the definition of done of every package
ps-module mono package <dir> "<description>" "<use when>" <keywords>   # a new package
```

Changes are made here, never in a split repo.

## Release

1. Write the changes under `## X.Y.Z` in `CHANGELOG.md` (and in the `CHANGELOG.md` of each changed package).
2. `ps-module mono set-version X.Y.Z` (root version, branch aliases, internal constraints).
3. Commit `Release X.Y.Z - <notes>` on `main` and push.

The release workflow checks everything, tags `vX.Y.Z`, makes the GitHub release, splits every package to its repo with
the same tag and updates Packagist. Never tag by hand.
