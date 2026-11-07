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
 * Keeps successful responses in a cache folder for a while (the workspace .cache/external), so a second search or
 * check, and the downloaded dist archives, do not go online again. Failures are not cached.
 */
final class CachedFetcher implements HttpFetcher
{
    /**
     * Wraps $inner; responses stay valid for $ttl seconds.
     */
    public function __construct(private readonly HttpFetcher $inner, private readonly string $dir, private readonly int $ttl = 86400)
    {
    }

    /**
     * The cached body, else the fetched one (then cached).
     */
    public function get(string $url): ?string
    {
        $file = rtrim($this->dir, '/') . '/' . sha1($url);
        if (is_file($file) && filemtime($file) > time() - $this->ttl) {
            return (string) file_get_contents($file);
        }
        $body = $this->inner->get($url);
        if ($body !== null) {
            if (!is_dir($this->dir)) {
                mkdir($this->dir, 0777, true);
            }
            file_put_contents($file, $body);
        }

        return $body;
    }
}
