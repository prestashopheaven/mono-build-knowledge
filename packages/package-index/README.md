# prestashopheaven/build-package-index

The index of the PrestaHaven package collection: every package of `libraries/mono-*/packages` with its public classes
and methods, a ranked search (`ps-module find`) and the one-line catalog for the generated `CONTEXT.md`.

## Use when

Before writing code for a module or package: search whether one of our packages already does it
(`ps-module find "<need>"`), or generate the package index and the one-line catalog for `CONTEXT.md`.

## Install

```
composer require --dev prestashopheaven/build-package-index
```

## Usage

```
php bin/package-index.php generate                 # .cache/index/<monorepo>.json + .cache/index/packages.json
php bin/package-index.php monorepo <dir> --out=index.json   # the index.json of one monorepo (release asset)
php bin/package-index.php find "local time of a shop"       # ranked: package, class, method, require line
php bin/package-index.php find "mail" --json --limit=5
php bin/package-index.php catalog                  # one line per package, for CONTEXT.md
```

`find` reads the packages of the workspace directly (never a stale index); `--index=FILE` searches a saved index
instead (a module outside the workspace).

In PHP:

```php
use PrestaShopHeaven\Build\PackageIndex\PackageIndex;
use PrestaShopHeaven\Build\PackageIndex\Search;

$index = PackageIndex::workspace($workspace . '/libraries');
foreach (Search::find($index, 'send mail to the customer', 5) as $hit) {
    echo $hit['package'], ' ', $hit['class'], ' ', $hit['method'], ': ', $hit['require'], "\n";
}
```

- `PhpApiScanner`: the public API of PHP source with PHP's tokenizer (also used by `build-prestashop-index`).
- `Catalog::workspaceLines($workspace)`: the catalog lines. The class has no dependencies, so the Stop hook can
  `require_once` the file without an autoloader.
- `TextTokens`: the search terms of a text (CamelCase and snake_case split, simple stemming).

---

Read-only split of [`prestashopheaven/mono-build-knowledge`](https://github.com/prestashopheaven/mono-build-knowledge): changes,
issues and pull requests go there. MIT License.
