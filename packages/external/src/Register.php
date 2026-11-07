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
 * The register of verdicts (docs/packages/external.md section 4): a check result as a Markdown table row, added as
 * the newest row under the table's header, so a later review sees why a package is used or why we wrote our own.
 *
 * @phpstan-import-type Result from CompatibilityCheck
 */
final class Register
{
    /** The heading of the register section. */
    public const HEADING = '## 4. Register of verdicts';

    /**
     * The register row of a result: | date | package | version range | for | verdict | reason | used in |.
     *
     * @param Result $result
     */
    public static function row(array $result, string $date, string $for = '–', string $usedIn = '–'): string
    {
        $failed = array_filter($result['risks'], static fn (array $r): bool => $r['level'] === 'fail');
        $reason = $failed === []
            ? 'automatic check B1-B8 passed' . ($result['deep'] ? '' : ' (quick)') . '; B5 manual'
            : implode('; ', array_map(static fn (array $r): string => $r['risk'] . ': ' . $r['message'], $failed));

        return '| ' . implode(' | ', array_map(static fn (string $cell): string => str_replace(['|', "\n"], ['\\|', ' '], $cell), [
            $date,
            '`' . $result['package'] . '`',
            $result['constraint'] !== '' ? '`' . $result['constraint'] . '`' : $result['version'],
            $for,
            $result['verdict'],
            $reason,
            $usedIn,
        ])) . ' |';
    }

    /**
     * Adds a row as the newest entry of the register table in a Markdown file.
     *
     * @throws \RuntimeException when the file has no register table
     */
    public static function record(string $file, string $row): void
    {
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $section = strpos($text, self::HEADING);
        $separator = $section === false ? false : strpos($text, "\n|---", $section);
        $end = $separator === false ? false : strpos($text, "\n", $separator + 1);
        if ($end === false) {
            throw new \RuntimeException("$file has no register table under \"" . self::HEADING . '".');
        }
        file_put_contents($file, substr($text, 0, $end + 1) . $row . "\n" . substr($text, $end + 1));
    }
}
