<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/MIT
 *
 * @author    prestahaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace PrestaShopHeaven\Build\PackageIndex;

/**
 * The generator of the package index (docs/packages/indexes.md section 1): reads the packages of the monorepos
 * libraries/mono-*\/packages/* (composer.json: name, description, kind, use-when, keywords; the version of the monorepo
 * root) and the public API of their src/: classes, interfaces, traits and enums with their public constants and methods,
 * and the functions. Writes one JSON per monorepo and the combined index of the workspace.
 *
 * @phpstan-type Method array{name: string, signature: string, static: bool, summary: string, deprecated: ?string}
 * @phpstan-type ClassEntry array{name: string, kind: string, file: string, summary: string, deprecated: ?string, constants: list<string>, methods: list<Method>}
 * @phpstan-type Package array{name: string, dir: string, kind: string, version: string, description: string, use-when: string, keywords: list<string>, php: string, prestashop: string, classes: list<ClassEntry>, functions: list<array{name: string, signature: string, summary: string}>}
 * @phpstan-type Monorepo array{name: string, dir: string, version: string, kind: string, packages: list<Package>}
 * @phpstan-type Index array{format: int, monorepos: list<Monorepo>}
 */
final class PackageIndex
{
    /** The version of the JSON layout. */
    public const FORMAT = 1;

    /** The combined index of the workspace, relative to the workspace folder. */
    public const WORKSPACE_FILE = '.cache/index/packages.json';

    /**
     * The index of one monorepo folder (a composer.json with extra.prestashopheaven.monorepo and packages/*).
     *
     * @return Monorepo
     *
     * @throws \RuntimeException when the folder has no monorepo composer.json
     */
    public static function monorepo(string $dir): array
    {
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        $root = self::readJson("$dir/composer.json");
        if (!isset($root['extra']['prestashopheaven']['monorepo'])) {
            throw new \RuntimeException("$dir/composer.json is not the root of a monorepo (no extra.prestashopheaven.monorepo).");
        }
        $version = (string) ($root['version'] ?? '');
        $packages = [];
        foreach (glob("$dir/packages/*/composer.json") ?: [] as $file) {
            $packages[] = self::package(dirname($file), self::readJson($file), $version);
        }
        usort($packages, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'name' => (string) ($root['name'] ?? basename($dir)),
            'dir' => basename($dir),
            'version' => $version,
            'kind' => (string) $root['extra']['prestashopheaven']['monorepo']['kind'],
            'packages' => $packages,
        ];
    }

    /**
     * The combined index of every monorepo in a libraries/ folder (libraries/mono-*), sorted by name.
     *
     * @return Index
     */
    public static function workspace(string $librariesDir): array
    {
        $monorepos = [];
        foreach (glob(rtrim(str_replace('\\', '/', $librariesDir), '/') . '/mono-*/composer.json') ?: [] as $file) {
            $root = json_decode((string) file_get_contents($file), true);
            if (is_array($root) && isset($root['extra']['prestashopheaven']['monorepo'])) {
                $monorepos[] = self::monorepo(dirname($file));
            }
        }
        usort($monorepos, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['format' => self::FORMAT, 'monorepos' => $monorepos];
    }

    /**
     * Generates the index of the workspace: <out>/<monorepo>.json per monorepo and <out>/packages.json combined
     * (default out: <workspace>/.cache/index). Returns the files written.
     *
     * @return list<string>
     */
    public static function generate(string $workspace, ?string $outDir = null): array
    {
        $workspace = rtrim(str_replace('\\', '/', $workspace), '/');
        $outDir = rtrim(str_replace('\\', '/', $outDir ?? $workspace . '/' . dirname(self::WORKSPACE_FILE)), '/');
        $index = self::workspace("$workspace/libraries");
        $written = [];
        foreach ($index['monorepos'] as $monorepo) {
            self::write(['format' => self::FORMAT, 'monorepos' => [$monorepo]], $written[] = "$outDir/{$monorepo['dir']}.json");
        }
        self::write($index, $written[] = "$outDir/packages.json");

        return $written;
    }

    /**
     * Writes an index as JSON (deterministic: the same packages give the same file).
     *
     * @param Index $index
     */
    public static function write(array $index, string $file): void
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }

    /**
     * Reads an index written by write().
     *
     * @return Index
     *
     * @throws \RuntimeException when the file is missing or not an index
     */
    public static function load(string $file): array
    {
        $index = self::readJson($file);
        if (($index['format'] ?? null) !== self::FORMAT || !is_array($index['monorepos'] ?? null)) {
            throw new \RuntimeException("$file is not a package index (format " . self::FORMAT . ').');
        }

        /* @var Index $index */
        return $index;
    }

    /**
     * One package: its composer.json metadata and the public API of its src/.
     *
     * @param array<string, mixed> $composer
     *
     * @return Package
     */
    private static function package(string $dir, array $composer, string $version): array
    {
        $meta = (array) ($composer['extra']['prestashopheaven'] ?? []);
        $classes = [];
        $functions = [];
        $scanner = new PhpApiScanner();
        foreach (self::phpFiles("$dir/src") as $file) {
            $api = $scanner->scan((string) file_get_contents($file));
            $relative = substr($file, strlen($dir) + 1);
            foreach ($api['classes'] as $class) {
                $classes[] = [
                    'name' => $class['name'],
                    'kind' => $class['kind'],
                    'file' => $relative,
                    'summary' => $class['summary'],
                    'deprecated' => $class['deprecated'],
                    'constants' => array_values(array_map(static fn (array $c): string => $c['name'], array_filter($class['constants'], static fn (array $c): bool => $c['visibility'] === 'public'))),
                    'methods' => array_values(array_map(
                        static fn (array $m): array => ['name' => $m['name'], 'signature' => $m['signature'], 'static' => $m['static'], 'summary' => $m['summary'], 'deprecated' => $m['deprecated']],
                        array_filter($class['methods'], static fn (array $m): bool => $m['visibility'] === 'public')
                    )),
                ];
            }
            foreach ($api['functions'] as $function) {
                $functions[] = ['name' => $function['name'], 'signature' => $function['signature'], 'summary' => $function['summary']];
            }
        }
        usort($classes, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return [
            'name' => (string) ($composer['name'] ?? basename($dir)),
            'dir' => 'packages/' . basename($dir),
            'kind' => (string) ($meta['kind'] ?? ''),
            'version' => $version,
            'description' => (string) ($composer['description'] ?? ''),
            'use-when' => (string) ($meta['use-when'] ?? ''),
            'keywords' => array_values(array_map('strval', (array) ($meta['keywords'] ?? []))),
            'php' => (string) ($composer['require']['php'] ?? ''),
            'prestashop' => (string) ($meta['prestashop'] ?? ''),
            'classes' => $classes,
            'functions' => $functions,
        ];
    }

    /**
     * Every .php file under a folder, sorted, with "/" separators.
     *
     * @return list<string>
     */
    public static function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Reads a JSON object.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException when the file is missing or not a JSON object
     */
    private static function readJson(string $file): array
    {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            throw new \RuntimeException("$file is missing or not valid JSON.");
        }

        return $data;
    }
}
