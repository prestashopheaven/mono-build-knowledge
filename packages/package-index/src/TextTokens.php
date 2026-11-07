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
 * Turns text into search terms: lowercase words, CamelCase and snake_case names split into their parts (the whole name
 * is kept too), common English words left out and a simple plural/verb ending removed, so "OrderStatusRules" matches
 * "order status rule". Shared by the package index and the PrestaShop index search.
 */
final class TextTokens
{
    /** Words that say nothing about a need. */
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'do', 'for', 'from', 'has', 'have', 'how', 'i', 'in',
        'into', 'is', 'it', 'its', 'my', 'need', 'of', 'on', 'or', 'our', 'so', 'that', 'the', 'their', 'this', 'to',
        'use', 'when', 'with', 'want', 'we', 'what', 'which', 'you', 'your',
    ];

    /**
     * The search terms of a text.
     *
     * @return list<string>
     */
    public static function of(string $text): array
    {
        $terms = [];
        foreach (preg_split('/[^\p{L}\p{N}_]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $parts = preg_split('/_+|(?<=\p{Ll})(?=\p{Lu})|(?<=\p{Lu})(?=\p{Lu}\p{Ll})|(?<=\p{L})(?=\p{N})/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) > 1) {
                $parts[] = str_replace('_', '', $word);
            }
            foreach ($parts as $part) {
                $term = self::stem(mb_strtolower($part));
                if (mb_strlen($term) > 1 && !in_array($term, self::STOPWORDS, true)) {
                    $terms[] = $term;
                }
            }
        }

        return $terms;
    }

    /**
     * Removes a simple English ending: "statuses" => "status", "rules" => "rule", "sending" => "send",
     * "categories" => "category".
     */
    public static function stem(string $word): string
    {
        if (mb_strlen($word) <= 3) {
            return $word;
        }
        // ending => [replacement, the shortest stem left]
        $rules = ['ies' => ['y', 2], 'sses' => ['ss', 2], 'uses' => ['us', 2], 'xes' => ['x', 2], 'ches' => ['ch', 2], 'shes' => ['sh', 2], 'ing' => ['', 3], 'ed' => ['', 3], 's' => ['', 3]];
        foreach ($rules as $ending => [$replacement, $shortest]) {
            if (str_ends_with($word, $ending) && mb_strlen($word) - strlen($ending) >= $shortest && !str_ends_with($word, 'ss') && !str_ends_with($word, 'us')) {
                return substr($word, 0, -strlen($ending)) . $replacement;
            }
        }

        return $word;
    }
}
