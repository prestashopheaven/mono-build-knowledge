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

namespace PrestaShopHeaven\Build\Pitfalls;

/**
 * Ranks pitfalls for a query: every query word (3+ characters, or a rule id like MS-04) found in the text counts, a word
 * in the area counts double, and a pitfall needs at least half of the query words to be listed.
 */
final class Search
{
    /**
     * The best $limit pitfalls for $query, best first (ties keep the collected order).
     *
     * @param list<Pitfall> $pitfalls
     *
     * @return list<array{score: int, pitfall: Pitfall}>
     */
    public static function find(array $pitfalls, string $query, int $limit = 10): array
    {
        $words = self::words($query);
        if ($words === [] || $limit < 1) {
            return [];
        }
        $hits = [];
        foreach ($pitfalls as $i => $pitfall) {
            $text = mb_strtolower($pitfall->text);
            $area = mb_strtolower($pitfall->area);
            $score = 0;
            $matched = 0;
            foreach ($words as $word) {
                $inText = str_contains($text, $word);
                $inArea = str_contains($area, $word);
                if ($inText || $inArea) {
                    ++$matched;
                    $score += ($inText ? 1 : 0) + ($inArea ? 2 : 0);
                }
            }
            if ($matched * 2 >= count($words)) {
                $hits[] = ['score' => $score, 'order' => $i, 'pitfall' => $pitfall];
            }
        }
        usort($hits, static fn (array $a, array $b): int => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_map(static fn (array $hit): array => ['score' => $hit['score'], 'pitfall' => $hit['pitfall']], array_slice($hits, 0, $limit));
    }

    /**
     * The search words of a query: lower case, 3+ characters or a rule id, without duplicates.
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $words = [];
        foreach (preg_split('/[^\p{L}\p{N}_-]+/u', mb_strtolower($query)) ?: [] as $word) {
            $word = trim($word, '-_');
            if (mb_strlen($word) >= 3 || preg_match('/^[a-z]{2,5}-\d{2}$/', $word) === 1) {
                $words[$word] = true;
            }
        }

        return array_keys($words);
    }

    /**
     * The hits as text lines: title, area and source, then the full text indented.
     *
     * @param list<array{score: int, pitfall: Pitfall}> $hits
     *
     * @return list<string>
     */
    public static function format(array $hits): array
    {
        if ($hits === []) {
            return ['No pitfalls found.'];
        }
        $lines = [];
        foreach ($hits as $hit) {
            $lines[] = '- ' . $hit['pitfall']->title() . " [{$hit['pitfall']->area}, {$hit['pitfall']->source}]";
            $lines[] = '    ' . $hit['pitfall']->text;
        }

        return $lines;
    }
}
