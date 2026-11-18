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
 * The one-line catalog of the package collection for the generated workspace CONTEXT.md (indexes.md section 3, "Catalog
 * in context"): one Markdown line per package with name, version, kind and use-when. Self-contained (no other class of
 * this package), so the Stop hook can require this file without an autoloader.
 */
final class Catalog
{
    /**
     * The catalog lines of every package of libraries/mono-*\/packages/* in a workspace, sorted by package name, without
     * a heading. Empty when there are no monorepos.
     *
     * @return list<string>
     */
    public static function workspaceLines(string $workspace): array
    {
        $packages = [];
        foreach (glob(rtrim(str_replace('\\', '/', $workspace), '/') . '/libraries/mono-*/composer.json') ?: [] as $rootFile) {
            $root = json_decode((string) file_get_contents($rootFile), true);
            if (!is_array($root) || !isset($root['extra']['prestashopheaven']['monorepo'])) {
                continue;
            }
            foreach (glob(dirname($rootFile) . '/packages/*/composer.json') ?: [] as $file) {
                $composer = json_decode((string) file_get_contents($file), true);
                if (is_array($composer) && isset($composer['name'])) {
                    $packages[(string) $composer['name']] = self::line($composer, (string) ($root['version'] ?? '?'), basename(dirname($rootFile)));
                }
            }
        }
        ksort($packages);

        return array_values($packages);
    }

    /**
     * One catalog line: "- `name` version (kind, monorepo): use when ...".
     *
     * @param array<string, mixed> $composer the package's composer.json
     */
    public static function line(array $composer, string $version, string $monorepo): string
    {
        $meta = is_array($composer['extra']['prestashopheaven'] ?? null) ? $composer['extra']['prestashopheaven'] : [];
        $useWhen = trim((string) preg_replace('/\s+/', ' ', (string) ($meta['use-when'] ?? $composer['description'] ?? '')), " \n\r\t\v\0");

        return '- `' . (string) $composer['name'] . "` $version (" . (string) ($meta['kind'] ?? '?') . ", $monorepo): " . str_replace('|', '/', $useWhen);
    }
}
