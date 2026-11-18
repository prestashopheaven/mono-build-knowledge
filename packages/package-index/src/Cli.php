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
 * The commands of bin/package-index.php (routed from ps-module find and the Stop hook):
 *
 *   generate [--workspace=DIR] [--out=DIR]      <out>/<monorepo>.json and <out>/packages.json (default .cache/index)
 *   monorepo <dir> --out=FILE                   the index.json of one monorepo (the release asset)
 *   find "<need>" [--limit=N] [--json] [--index=FILE] [--workspace=DIR]   ranked search
 *   catalog [--workspace=DIR]                   the catalog lines for CONTEXT.md
 *
 * Without --index, find reads the packages of the workspace directly, so it never works on a stale index.
 * Exit code 0 = ok, 1 = failed, 2 = wrong usage.
 */
final class Cli
{
    /** The usage line. */
    public const USAGE = 'Usage: package-index.php generate [--workspace=DIR] [--out=DIR] | monorepo <dir> --out=FILE | find "<need>" [--limit=N] [--json] [--index=FILE] | catalog [--workspace=DIR]';

    /**
     * Runs a command; writes to $out and $err.
     *
     * @param list<string> $args the arguments without the script name
     * @param resource $out
     * @param resource $err
     * @param string|null $workspace the workspace found around the script (Workspace::find()); --workspace=DIR overrides it
     */
    public static function run(array $args, $out, $err, ?string $workspace): int
    {
        [$options, $rest] = Workspace::options($args);
        $command = $rest[0] ?? '';
        $workspace = $options['workspace'] ?? $workspace;
        try {
            switch ($command) {
                case 'generate':
                    foreach (PackageIndex::generate(self::requireWorkspace($workspace), $options['out'] ?? null) as $file) {
                        fwrite($out, "Wrote $file\n");
                    }

                    return 0;
                case 'monorepo':
                    if (!isset($rest[1], $options['out'])) {
                        throw new \InvalidArgumentException(self::USAGE);
                    }
                    PackageIndex::write(['format' => PackageIndex::FORMAT, 'monorepos' => [PackageIndex::monorepo($rest[1])]], $options['out']);
                    fwrite($out, "Wrote {$options['out']}\n");

                    return 0;
                case 'find':
                    if (trim($rest[1] ?? '', " \n\r\t\v\0") === '') {
                        throw new \InvalidArgumentException(self::USAGE);
                    }
                    $index = isset($options['index']) ? PackageIndex::load($options['index']) : PackageIndex::workspace(self::requireWorkspace($workspace) . '/libraries');
                    $hits = Search::find($index, $rest[1], (int) ($options['limit'] ?? 10));
                    fwrite($out, isset($options['json'])
                        ? json_encode($hits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
                        : implode("\n", Search::format($hits)) . "\n");

                    return 0;
                case 'catalog':
                    $lines = Catalog::workspaceLines(self::requireWorkspace($workspace));
                    fwrite($out, $lines === [] ? '' : implode("\n", $lines) . "\n");

                    return 0;
                default:
                    throw new \InvalidArgumentException(self::USAGE);
            }
        } catch (\InvalidArgumentException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 2;
        } catch (\RuntimeException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * The workspace folder.
     *
     * @throws \RuntimeException when there is none
     */
    private static function requireWorkspace(?string $workspace): string
    {
        if ($workspace === null) {
            throw new \RuntimeException('No PrestaHaven workspace (a folder with libraries/ and modules/) found; pass --workspace=DIR.');
        }

        return $workspace;
    }
}
