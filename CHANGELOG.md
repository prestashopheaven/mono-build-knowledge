# Changelog

All packages of this monorepo share one version. Per package details: its own `CHANGELOG.md`.

## 1.1.1

- `build-pitfalls`: three curated pitfalls from the Cart Journey 1.4.1 pilot (Bootstrap `!important`, translated grid
  row-link classes, Cloudflare purge 401).

## 1.1.0

- New package `build-pitfalls`: the pitfalls index of the Module-Factory (`ps-module find --pitfalls "<area>"`),
  collected live from the Pitfalls notes of every module's CONTEXT.md, the Valkuilen sections of the feature recipes
  and a curated list (`pitfalls.json`); 368 pitfalls at the first run.

## 1.0.2

- `build-prestashop-index`: `ps check` accepts the hooks of other modules a module listens to, declared in
  `ModuleDefinition::OTHER_MODULE_HOOKS` (hook => module, such as `'registerGDPRConsent' => 'psgdpr'`); a misspelled
  hook name is still an error.

## 1.0.1

- `build-prestashop-index`: `ps check` accepts the hooks a module provides for other modules, declared in
  `ModuleDefinition::PROVIDED_HOOKS`; a misspelled hook name is still an error.

## 1.0.0

- Monorepo created (2026-11-07) with `build-package-index`, `build-prestashop-index` (data for PrestaShop 8.1.0, 8.2.8 and 9.2.0) and `build-external`.
