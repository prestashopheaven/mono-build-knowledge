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

namespace PrestaShopHeaven\Build\Pitfalls;

/**
 * The commands of bin/pitfalls.php (routed from ps-module find --pitfalls):
 *
 *   find "<query>" [--limit=N] [--json] [--workspace=DIR]   the best pitfalls for the query
 *   stats [--workspace=DIR]                                 how many pitfalls per source kind and area
 *
 * Exit code 0 = ok, 1 = failed, 2 = wrong usage.
 */
final class Cli
{
    /** The usage line. */
    public const USAGE = 'Usage: pitfalls.php find "<query>" [--limit=N] [--json] | stats  [--workspace=DIR]';

    /**
     * Runs a command; $collect gives the pitfalls of a workspace folder. Writes to $out and $err.
     *
     * @param list<string> $args
     * @param callable(string): list<Pitfall> $collect
     * @param resource $out
     * @param resource $err
     */
    public static function run(array $args, ?string $workspace, callable $collect, $out, $err): int
    {
        $options = [];
        $rest = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $m) === 1) {
                $options[$m[1]] = $m[2] ?? '1';
            } else {
                $rest[] = $arg;
            }
        }
        $workspace = $options['workspace'] ?? $workspace;
        $command = $rest[0] ?? '';
        if (!in_array($command, ['find', 'stats'], true) || ($command === 'find' && trim($rest[1] ?? '', " \n\r\t\v\0") === '')) {
            fwrite($err, self::USAGE . "\n");

            return 2;
        }
        if ($workspace === null) {
            fwrite($err, "No PrestaHaven workspace (a folder with libraries/ and modules/) found; pass --workspace=DIR.\n");

            return 1;
        }
        try {
            $pitfalls = $collect($workspace);
        } catch (\RuntimeException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 1;
        }
        if ($command === 'stats') {
            $areas = [];
            foreach ($pitfalls as $pitfall) {
                $kind = str_starts_with($pitfall->source, 'modules/') ? 'modules' : (str_starts_with($pitfall->source, 'features/') ? 'recipes' : 'curated');
                $areas[$kind] = ($areas[$kind] ?? 0) + 1;
            }
            ksort($areas);
            fwrite($out, 'Pitfalls: ' . count($pitfalls) . ' (' . implode(', ', array_map(static fn (string $k, int $n): string => "$k $n", array_keys($areas), $areas)) . ")\n");

            return 0;
        }
        $hits = Search::find($pitfalls, $rest[1], max(1, (int) ($options['limit'] ?? 10)));
        if (isset($options['json'])) {
            fwrite($out, json_encode(array_map(static fn (array $hit): array => ['score' => $hit['score']] + $hit['pitfall']->toArray(), $hits), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        } else {
            fwrite($out, implode("\n", Search::format($hits)) . "\n");
        }

        return 0;
    }
}
