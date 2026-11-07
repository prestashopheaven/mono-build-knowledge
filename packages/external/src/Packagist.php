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

/**
 * The Packagist API the external search needs: the search (search.json), the versions of a package with their
 * metadata and security advisories (repo.packagist.org/p2/<name>.json, expanded from Composer's minified form), the
 * package page data (abandoned, downloads) and the dist archive of a version.
 *
 * @phpstan-type VersionData array<string, mixed>
 * @phpstan-type SearchResult array{name: string, description: string, downloads: int, favers: int, url: string}
 */
final class Packagist
{
    /** The search API. */
    public const SEARCH_URL = 'https://packagist.org/search.json?q=';

    /** The metadata of the versions of a package. */
    public const P2_URL = 'https://repo.packagist.org/p2/';

    /** The package page data. */
    public const PACKAGE_URL = 'https://packagist.org/packages/';

    /** @var array<string, array{versions: list<VersionData>, advisories: list<array{advisoryId: string, affectedVersions: string}>}|null> */
    private array $p2 = [];

    /**
     * Uses $http for every request.
     */
    public function __construct(private readonly HttpFetcher $http)
    {
    }

    /**
     * The best search results for a query.
     *
     * @return list<SearchResult>
     */
    public function search(string $query, int $limit = 10): array
    {
        $data = $this->json(self::SEARCH_URL . rawurlencode($query) . '&per_page=' . max(1, min(50, $limit)));
        $results = [];
        foreach (array_slice((array) ($data['results'] ?? []), 0, $limit) as $result) {
            if (is_array($result) && isset($result['name'])) {
                $results[] = [
                    'name' => (string) $result['name'],
                    'description' => (string) ($result['description'] ?? ''),
                    'downloads' => (int) ($result['downloads'] ?? 0),
                    'favers' => (int) ($result['favers'] ?? 0),
                    'url' => (string) ($result['url'] ?? ''),
                ];
            }
        }

        return $results;
    }

    /**
     * Every tagged version of a package with its full metadata, newest first as Packagist lists them; empty when the
     * package does not exist.
     *
     * @return list<VersionData>
     */
    public function versions(string $name): array
    {
        return $this->p2($name)['versions'] ?? [];
    }

    /**
     * The security advisories of a package (advisoryId, affectedVersions as a Composer constraint).
     *
     * @return list<array{advisoryId: string, affectedVersions: string}>
     */
    public function advisories(string $name): array
    {
        return $this->p2($name)['advisories'] ?? [];
    }

    /**
     * The package page data: abandoned (false, true or the replacement) and the downloads; null when unknown.
     *
     * @return array{abandoned: bool|string, downloads: int, monthly: int, repository: string}|null
     */
    public function info(string $name): ?array
    {
        $data = $this->json(self::PACKAGE_URL . $name . '.json');
        $package = $data['package'] ?? null;
        if (!is_array($package)) {
            return null;
        }
        $abandoned = $package['abandoned'] ?? false;

        return [
            'abandoned' => is_string($abandoned) ? $abandoned : (bool) $abandoned,
            'downloads' => (int) ($package['downloads']['total'] ?? 0),
            'monthly' => (int) ($package['downloads']['monthly'] ?? 0),
            'repository' => (string) ($package['repository'] ?? ''),
        ];
    }

    /**
     * The dist archive (zip) of a version, or null.
     *
     * @param VersionData $version
     */
    public function dist(array $version): ?string
    {
        $url = (string) ($version['dist']['url'] ?? '');

        return $url === '' ? null : $this->http->get($url);
    }

    /**
     * Expands Composer's minified version list: each entry holds only what changed since the one before, "__unset"
     * removes a key.
     *
     * @param list<array<string, mixed>> $versions
     *
     * @return list<array<string, mixed>>
     */
    public static function expand(array $versions): array
    {
        $expanded = [];
        $current = null;
        foreach ($versions as $data) {
            if ($current === null) {
                $current = $data;
            } else {
                foreach ($data as $key => $value) {
                    if ($value === '__unset') {
                        unset($current[$key]);
                    } else {
                        $current[$key] = $value;
                    }
                }
            }
            $expanded[] = $current;
        }

        return $expanded;
    }

    /**
     * The p2 data of a package (fetched once per run).
     *
     * @return array{versions: list<VersionData>, advisories: list<array{advisoryId: string, affectedVersions: string}>}|null
     */
    private function p2(string $name): ?array
    {
        $name = strtolower($name);
        if (!array_key_exists($name, $this->p2)) {
            $data = $this->json(self::P2_URL . $name . '.json');
            $versions = $data['packages'][$name] ?? null;
            if (!is_array($versions)) {
                $this->p2[$name] = null;
            } else {
                $versions = array_values(array_filter($versions, 'is_array'));
                $advisories = [];
                foreach ((array) ($data['security-advisories'] ?? []) as $advisory) {
                    if (is_array($advisory) && isset($advisory['affectedVersions'])) {
                        $advisories[] = ['advisoryId' => (string) ($advisory['advisoryId'] ?? ''), 'affectedVersions' => (string) $advisory['affectedVersions']];
                    }
                }
                $this->p2[$name] = [
                    'versions' => ($data['minified'] ?? '') === 'composer/2.0' ? self::expand($versions) : $versions,
                    'advisories' => $advisories,
                ];
            }
        }

        return $this->p2[$name];
    }

    /**
     * A JSON object from a URL; empty when it fails.
     *
     * @return array<mixed>
     */
    private function json(string $url): array
    {
        $body = $this->http->get($url);
        $data = $body === null ? null : json_decode($body, true);

        return is_array($data) ? $data : [];
    }
}
