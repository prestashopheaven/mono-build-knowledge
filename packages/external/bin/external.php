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
 * The external search and check (ps-module find --external | ps-module external check): find "<need>" | check
 * <vendor/name>[:<constraint>]. See PrestaShopHeaven\Build\External\Cli for the options. Goes online (Packagist, npm,
 * GitHub dist archives); responses are cached for a day in <workspace>/.cache/external.
 */

declare(strict_types=1);

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../../autoload.php', __DIR__ . '/../../../vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}
ini_set('memory_limit', '1G');
$args = array_slice($argv, 1);

exit(PrestaShopHeaven\Build\External\Cli::run($args, STDOUT, STDERR, PrestaShopHeaven\Build\External\Cli::fetcher($args, PrestaShopHeaven\Build\PackageIndex\Workspace::find([(string) getcwd(), __DIR__])), new DateTimeImmutable()));
