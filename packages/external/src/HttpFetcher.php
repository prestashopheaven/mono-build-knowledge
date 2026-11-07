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
 * Fetches a URL. Injected everywhere the external search goes online, so the unit tests run on recorded responses and
 * never touch the network.
 */
interface HttpFetcher
{
    /**
     * The body of a URL, or null when it cannot be fetched (network error, a status other than 2xx).
     */
    public function get(string $url): ?string;
}
