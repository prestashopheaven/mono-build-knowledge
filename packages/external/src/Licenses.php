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
 * Risk B6: the license allowlist of decision D20 (only free permissive licenses; no copyleft, commercial, paid,
 * proprietary or missing license). A Composer license list, or an SPDX "A OR B" expression, means the user may choose:
 * it passes when one of the choices is allowed.
 */
final class Licenses
{
    /** The allowed SPDX identifiers (D20). */
    public const ALLOWED = ['MIT', 'BSD-2-Clause', 'BSD-3-Clause', 'Apache-2.0', 'ISC', '0BSD', 'Zlib', 'Unlicense', 'CC0-1.0'];

    /**
     * True when one of the licenses (or one choice of an "OR" expression) is on the allowlist.
     *
     * @param list<string> $licenses
     */
    public static function allowed(array $licenses): bool
    {
        foreach ($licenses as $license) {
            foreach (preg_split('/\s+or\s+|\s*\|\|?\s*|\//i', trim($license, ' ()')) ?: [] as $choice) {
                if (in_array(strtolower(trim($choice, ' ()')), array_map('strtolower', self::ALLOWED), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The licenses as text: "MIT", "MIT OR GPL-2.0", or "none".
     *
     * @param list<string> $licenses
     */
    public static function describe(array $licenses): string
    {
        return $licenses === [] ? 'none' : implode(' OR ', $licenses);
    }
}
