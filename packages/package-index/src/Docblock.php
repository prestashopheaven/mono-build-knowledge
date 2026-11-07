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

namespace PrestaShopHeaven\Build\PackageIndex;

/**
 * Reads the parts of a docblock the indexes need: the first sentence of its description and its @deprecated tag.
 */
final class Docblock
{
    /** The longest summary kept, in characters. */
    public const MAX_SUMMARY = 200;

    /**
     * The first sentence of the description (the text before the first @tag or blank line), on one line; '' when the
     * docblock has no description.
     */
    public static function summary(string $doc): string
    {
        $text = [];
        foreach (self::lines($doc) as $line) {
            if ($line === '' && $text !== []) {
                break;
            }
            if (str_starts_with($line, '@')) {
                break;
            }
            if ($line !== '') {
                $text[] = $line;
            }
        }
        $summary = implode(' ', $text);
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $summary, $m)) {
            $summary = $m[1];
        }
        if (mb_strlen($summary) > self::MAX_SUMMARY) {
            $summary = rtrim(mb_substr($summary, 0, self::MAX_SUMMARY - 1)) . '…';
        }

        return $summary;
    }

    /**
     * The text of the @deprecated tag ('' when it has none), or null when the docblock has no such tag.
     */
    public static function deprecation(string $doc): ?string
    {
        foreach (self::lines($doc) as $line) {
            if (preg_match('/^@deprecated\b\s*(.*)$/', $line, $m)) {
                return trim($m[1]);
            }
        }

        return null;
    }

    /**
     * The lines of a docblock without the comment markers, trimmed.
     *
     * @return list<string>
     */
    private static function lines(string $doc): array
    {
        $doc = (string) preg_replace(['#^\s*/\*\*#', '#\*/\s*$#'], '', $doc);
        $lines = [];
        foreach (preg_split('/\R/', $doc) ?: [] as $line) {
            $lines[] = trim((string) preg_replace('/^\s*\*?/', '', $line));
        }

        return $lines;
    }
}
