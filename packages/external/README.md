# prestashopheaven/build-external

The external package search on Packagist and npm (`ps-module find --external`) and the compatibility check of risks
B1-B8 (`ps-module external check`): PHP range, clashes with what PrestaShop ships, scoping, license, health and size.

## Use when

Before writing code that an external package might already do: search Packagist and npm, and check whether a PHP
package can be used in our modules without breaking them (use through a wrapper, or write our own with the risk
numbers).

## Install

```
composer require --dev prestashopheaven/build-external
```

## Usage

```
php bin/external.php find "clock"                     # Packagist + npm; quick verdicts for the first 3 PHP results
php bin/external.php find "pdf" --limit=10 --checked=5 --no-npm --json
php bin/external.php check psr/clock                  # deep: downloads and scans the dist archives
php bin/external.php check symfony/clock:^6.4 --quick # metadata only
php bin/external.php check psr/clock --record=docs/packages/external.md --for=runtime-clock --used-in=runtime-clock
```

`check` exits with 0 for "use, through a wrapper" and 1 for "write our own". The risks (`docs/packages/external.md`):

| Risk | Automatic part |
|---|---|
| B1 | the PHP constraint of the package and every shipped dependency allows PHP 8.1 to 8.5 |
| B2, B3 | per indexed PrestaShop release (vendor list of `build-prestashop-index`): a dependency PrestaShop ships in a fitting version in every release is used from PrestaShop; otherwise shipped scoped, which a shared interface package (psr/container, psr/log, Symfony contracts, ...) cannot be |
| B4 | global functions (autoload.files, polyfills) fail; namespaced autoload.files, `function_exists()` and class names in strings warn (deep check scans the archives) |
| B5 | manual: the validator on a test zip |
| B6 | the license of the package and every shipped dependency is on the allowlist of D20 |
| B7 | abandoned, no release in two years (warning), security advisories for the chosen versions |
| B8 | the size of the shipped archives (deep check) |

Responses are cached for a day in `<workspace>/.cache/external` (`--cache=DIR`). In PHP every class takes an
`HttpFetcher`, so tests run on recorded responses.

---

Read-only split of [`prestashopheaven/mono-build-knowledge`](https://github.com/prestashopheaven/mono-build-knowledge): changes,
issues and pull requests go there. MIT License.
