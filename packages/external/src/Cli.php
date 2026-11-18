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

namespace PrestaShopHeaven\Build\External;

use PrestaShopHeaven\Build\PackageIndex\Workspace;
use PrestaShopHeaven\Build\PrestashopIndex\IndexStore;

/**
 * The commands of bin/external.php (routed from ps-module find --external and ps-module external check):
 *
 *   find "<need>" [--limit=N] [--checked=N] [--no-npm] [--json]         Packagist + npm, quick verdicts for the top N
 *   check <vendor/name>[:<constraint>] [--quick] [--json] [--record=FILE] [--for=TEXT] [--used-in=TEXT]
 *                                                                         the check of risks B1-B8 (deep by default)
 *
 * Both take --ps-min=X / --ps-max=Y (the PrestaShop releases to compare with; default every indexed one), --data=DIR
 * (the PrestaShop index) and --cache=DIR (default <workspace>/.cache/external, responses kept for a day).
 * Exit code 0 = ok (check: verdict "use"), 1 = failed (check: "write our own"), 2 = wrong usage.
 */
final class Cli
{
    /** The usage line. */
    public const USAGE = 'Usage: external.php find "<need>" [--limit=N] [--checked=N] [--no-npm] [--json] | check <vendor/name>[:<constraint>] [--quick] [--json] [--record=FILE] [--for=TEXT] [--used-in=TEXT]  (both: [--ps-min=X] [--ps-max=Y] [--data=DIR] [--cache=DIR])';

    /**
     * Runs a command with the given fetcher; writes to $out and $err.
     *
     * @param list<string> $args the arguments without the script name
     * @param resource $out
     * @param resource $err
     */
    public static function run(array $args, $out, $err, HttpFetcher $http, \DateTimeImmutable $now): int
    {
        [$options, $rest] = Workspace::options($args);
        try {
            $command = $rest[0] ?? '';
            $subject = trim($rest[1] ?? '', " \n\r\t\v\0");
            if (!in_array($command, ['find', 'check'], true) || $subject === '') {
                throw new \InvalidArgumentException(self::USAGE);
            }
            $packagist = new Packagist($http);
            $prestashop = (new IndexStore(IndexStore::defaultDir($options['data'] ?? null)))->symbols();
            $check = new CompatibilityCheck($packagist, $prestashop, $command === 'check' && !isset($options['quick']), $now, $options['ps-min'] ?? null, $options['ps-max'] ?? null);
            if ($command === 'find') {
                $found = (new ExternalSearch($packagist, new Npm($http), $check))->find($subject, (int) ($options['limit'] ?? 5), (int) ($options['checked'] ?? 3), !isset($options['no-npm']));
                fwrite($out, isset($options['json']) ? self::json($found) : implode("\n", ExternalSearch::format($found)) . "\n");

                return 0;
            }
            $result = $check->check($subject);
            fwrite($out, isset($options['json']) ? self::json($result) : implode("\n", CompatibilityCheck::format($result)) . "\n");
            if (isset($options['record'])) {
                Register::record($options['record'], Register::row($result, $now->format('Y-m-d'), $options['for'] ?? '–', $options['used-in'] ?? '–'));
                fwrite($out, "Recorded the verdict in {$options['record']}.\n");
            }

            return $result['reasons'] === [] ? 0 : 1;
        } catch (\InvalidArgumentException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 2;
        } catch (\RuntimeException $e) {
            fwrite($err, $e->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * The real fetcher, cached in <workspace>/.cache/external (or --cache=DIR; no cache outside a workspace).
     *
     * @param list<string> $args
     */
    public static function fetcher(array $args, ?string $workspace): HttpFetcher
    {
        $cache = Workspace::options($args)[0]['cache'] ?? null;
        $cache ??= $workspace === null ? null : "$workspace/.cache/external";

        return $cache === null ? new StreamFetcher() : new CachedFetcher(new StreamFetcher(), $cache);
    }

    /**
     * Pretty JSON with a trailing newline.
     *
     * @param array<mixed> $data
     */
    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
}
