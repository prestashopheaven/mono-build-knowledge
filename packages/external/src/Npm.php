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
 * The npm registry search for JavaScript and TypeScript needs (registry.npmjs.org/-/v1/search).
 *
 * @phpstan-type NpmResult array{name: string, version: string, description: string, license: string, monthly: int, date: string, url: string}
 */
final class Npm
{
    /** The search API. */
    public const SEARCH_URL = 'https://registry.npmjs.org/-/v1/search?text=';

    /**
     * Uses $http for every request.
     */
    public function __construct(private readonly HttpFetcher $http)
    {
    }

    /**
     * The best search results for a query.
     *
     * @return list<NpmResult>
     */
    public function search(string $query, int $limit = 10): array
    {
        $body = $this->http->get(self::SEARCH_URL . rawurlencode($query) . '&size=' . max(1, min(50, $limit)));
        $data = $body === null ? null : json_decode($body, true);
        $results = [];
        foreach (is_array($data) ? (array) ($data['objects'] ?? []) : [] as $object) {
            $package = is_array($object) ? ($object['package'] ?? null) : null;
            if (!is_array($package) || !isset($package['name'])) {
                continue;
            }
            $results[] = [
                'name' => (string) $package['name'],
                'version' => (string) ($package['version'] ?? ''),
                'description' => (string) ($package['description'] ?? ''),
                'license' => is_string($package['license'] ?? null) ? $package['license'] : '',
                'monthly' => (int) ($object['downloads']['monthly'] ?? 0),
                'date' => substr((string) ($package['date'] ?? ''), 0, 10),
                'url' => (string) ($package['links']['npm'] ?? ''),
            ];
        }

        return array_slice($results, 0, $limit);
    }
}
