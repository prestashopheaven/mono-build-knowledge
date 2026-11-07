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
 * The real fetcher: PHP streams with a user agent (GitHub's zipball URLs require one), redirects followed and a
 * timeout. A status other than 2xx gives null. Also reads local files (no status), which the tests use.
 */
final class StreamFetcher implements HttpFetcher
{
    /** The user agent sent to Packagist, npm and GitHub. */
    public const USER_AGENT = 'PrestaHaven build-external (+https://prestahaven.com)';

    /**
     * Sets the timeout in seconds.
     */
    public function __construct(private readonly int $timeout = 30)
    {
    }

    /**
     * The body of a URL or local file, or null.
     */
    public function get(string $url): ?string
    {
        $context = stream_context_create(['http' => [
            'user_agent' => self::USER_AGENT,
            'timeout' => $this->timeout,
            'follow_location' => 1,
            'ignore_errors' => true,
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            return null;
        }
        $headers = stream_get_meta_data($stream)['wrapper_data'] ?? [];
        $body = stream_get_contents($stream);
        fclose($stream);
        $status = self::status(is_array($headers) ? $headers : []);

        return $body !== false && $status >= 200 && $status < 300 ? $body : null;
    }

    /**
     * The HTTP status of response headers: the last status line (the one after the redirects); 200 without one (a
     * local file).
     *
     * @param array<mixed> $headers
     */
    public static function status(array $headers): int
    {
        $status = 200;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
