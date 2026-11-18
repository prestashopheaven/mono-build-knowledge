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

/*
 * The pitfalls index (ps-module find --pitfalls): find "<query>" | stats. See PrestaShopHeaven\Build\Pitfalls\Cli.
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

$workspace = null;
foreach ([(string) getcwd(), __DIR__] as $start) {
    $dir = rtrim(str_replace('\\', '/', (string) (realpath($start) ?: $start)), '/');
    for ($i = 0; $i < 12 && $dir !== '' && $dir !== dirname($dir); ++$i, $dir = dirname($dir)) {
        if (is_dir("$dir/libraries") && is_dir("$dir/modules")) {
            $workspace = $dir;
            break 2;
        }
    }
}

exit(PrestaShopHeaven\Build\Pitfalls\Cli::run(
    array_slice($argv, 1),
    $workspace,
    static fn (string $dir): array => PrestaShopHeaven\Build\Pitfalls\Collector::workspace($dir),
    STDOUT,
    STDERR,
));
