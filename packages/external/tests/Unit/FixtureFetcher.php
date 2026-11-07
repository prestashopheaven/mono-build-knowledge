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

namespace PrestaShopHeaven\Build\External\Tests\Unit;

use PrestaShopHeaven\Build\External\HttpFetcher;
use PrestaShopHeaven\Build\External\Packagist;
use PrestaShopHeaven\Build\PrestashopIndex\SymbolIndex;

/**
 * The fetcher of the tests: answers from recorded responses (tests/fixtures) and from packages defined in the test,
 * never from the network; counts the requests per URL.
 */
final class FixtureFetcher implements HttpFetcher
{
    /** @var array<string, string|null> */
    public array $responses = [];

    /** @var array<string, int> */
    public array $requests = [];

    /**
     * Starts with the recorded responses of tests/fixtures.
     */
    public function __construct()
    {
        $fixtures = dirname(__DIR__) . '/fixtures';
        $this->responses[Packagist::P2_URL . 'psr/clock.json'] = (string) file_get_contents("$fixtures/p2-psr-clock.json");
        $this->responses[Packagist::P2_URL . 'symfony/clock.json'] = (string) file_get_contents("$fixtures/p2-symfony-clock.json");
        $this->responses[Packagist::P2_URL . 'symfony/polyfill-php83.json'] = (string) file_get_contents("$fixtures/p2-symfony-polyfill-php83.json");
        $this->responses[Packagist::SEARCH_URL . 'clock&per_page=3'] = (string) file_get_contents("$fixtures/packagist-search-clock.json");
        $this->responses['https://registry.npmjs.org/-/v1/search?text=clock&size=3'] = (string) file_get_contents("$fixtures/npm-search-clock.json");
    }

    /**
     * The recorded or defined response, or null.
     */
    public function get(string $url): ?string
    {
        $this->requests[$url] = ($this->requests[$url] ?? 0) + 1;

        return $this->responses[$url] ?? null;
    }

    /**
     * Defines a package on the fake Packagist: its versions (p2, minified like the real one when $minified), its
     * advisories and optionally its package page.
     *
     * @param list<array<string, mixed>> $versions
     * @param list<array{advisoryId: string, affectedVersions: string}> $advisories
     * @param array<string, mixed>|null $page
     */
    public function package(string $name, array $versions, array $advisories = [], ?array $page = null, bool $minified = false): void
    {
        foreach ($versions as $n => $version) {
            $versions[$n] = ['name' => $name] + $version;
        }
        if ($minified) {
            $versions = self::minify($versions);
        }
        $this->responses[Packagist::P2_URL . "$name.json"] = (string) json_encode(['packages' => [$name => $versions], 'security-advisories' => $advisories] + ($minified ? ['minified' => 'composer/2.0'] : []));
        if ($page !== null) {
            $this->responses[Packagist::PACKAGE_URL . "$name.json"] = (string) json_encode(['package' => $page]);
        }
    }

    /**
     * A zip archive with the given files.
     *
     * @param array<string, string> $files
     */
    public static function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'fxzip');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    /**
     * The PrestaShop index of the tests: releases 8.1.0 and 9.0.0 with their vendor packages.
     */
    public static function prestashop(): SymbolIndex
    {
        return new SymbolIndex(['format' => 1, 'versions' => ['8.1.0', '9.0.0'], 'classes' => [], 'aliases' => [], 'functions' => [], 'hooks' => [], 'hookAliases' => [], 'hookPatterns' => [], 'services' => [], 'servicePrefixes' => [], 'config' => [], 'tables' => [], 'vendor' => [
            'psr/clock' => ['9.0.0' => '1.0.0'],
            'psr/log' => ['8.1.0' => '1.1.4', '9.0.0' => '3.0.0'],
            'psr/container' => ['8.1.0' => '1.1.2', '9.0.0' => '2.0.2'],
            'symfony/polyfill-mbstring' => ['8.1.0' => 'v1.27.0', '9.0.0' => 'v1.30.0'],
            'guzzlehttp/guzzle' => ['8.1.0' => '7.5.0', '9.0.0' => '7.9.2'],
        ]]);
    }

    /**
     * Composer's minified form: each version holds only what changed, "__unset" for a removed key.
     *
     * @param list<array<string, mixed>> $versions
     *
     * @return list<array<string, mixed>>
     */
    private static function minify(array $versions): array
    {
        $out = [];
        $previous = null;
        foreach ($versions as $version) {
            if ($previous === null) {
                $out[] = $version;
            } else {
                $diff = [];
                foreach ($version as $key => $value) {
                    if (!array_key_exists($key, $previous) || $previous[$key] !== $value) {
                        $diff[$key] = $value;
                    }
                }
                foreach (array_diff_key($previous, $version) as $key => $value) {
                    $diff[$key] = '__unset';
                }
                $out[] = $diff;
            }
            $previous = $version;
        }

        return $out;
    }
}
