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
 * The ranked search of the package index (ps-module find "<need>"): every package, class and method gets a score from
 * the search terms found in its name, use-when text, keywords, description and docblocks (term frequency times the
 * weight of the field; a term that only starts a word counts half). A class or method also gets a share of its
 * package's score, so the API of the best package comes first.
 *
 * @phpstan-import-type Index from PackageIndex
 *
 * @phpstan-type Hit array{score: float, type: string, package: string, class: string, method: string, description: string, require: string, kind: string, version: string}
 */
final class Search
{
    /** Weight per field of a package. */
    private const PACKAGE_WEIGHTS = ['name' => 6.0, 'keywords' => 4.0, 'use-when' => 3.0, 'description' => 2.0];

    /** Weight of the parts of a class. */
    private const CLASS_WEIGHTS = ['name' => 5.0, 'summary' => 2.0];

    /** Weight of the parts of a method. */
    private const METHOD_WEIGHTS = ['name' => 3.0, 'summary' => 1.5];

    /** Share of the package's score that its classes and methods get. */
    private const PACKAGE_SHARE = 0.3;

    /**
     * The best hits for a need, highest score first.
     *
     * @param Index $index
     *
     * @return list<Hit>
     */
    public static function find(array $index, string $need, int $limit = 10): array
    {
        $terms = array_values(array_unique(TextTokens::of($need)));
        if ($terms === []) {
            return [];
        }
        $hits = [];
        foreach ($index['monorepos'] as $monorepo) {
            foreach ($monorepo['packages'] as $package) {
                $packageScore = self::score($terms, [
                    'name' => (string) preg_replace('#^[^/]+/#', '', $package['name']),
                    'keywords' => implode(' ', $package['keywords']),
                    'use-when' => $package['use-when'],
                    'description' => $package['description'],
                ], self::PACKAGE_WEIGHTS);
                $base = ['package' => $package['name'], 'require' => 'composer require ' . ($package['kind'] === 'build' ? '--dev ' : '') . $package['name'] . ($package['version'] !== '' ? ':^' . self::minor($package['version']) : ''), 'kind' => $package['kind'], 'version' => $package['version']];
                if ($packageScore > 0) {
                    $hits[] = ['score' => $packageScore, 'type' => 'package', 'class' => '', 'method' => '', 'description' => $package['use-when'] !== '' ? $package['use-when'] : $package['description']] + $base;
                }
                foreach ($package['classes'] as $class) {
                    $short = substr($class['name'], (int) strrpos('\\' . $class['name'], '\\'));
                    $classScore = self::score($terms, ['name' => $short, 'summary' => $class['summary']], self::CLASS_WEIGHTS);
                    if ($classScore > 0) {
                        $hits[] = ['score' => $classScore + self::PACKAGE_SHARE * $packageScore, 'type' => $class['kind'], 'class' => $class['name'], 'method' => '', 'description' => $class['summary']] + $base;
                    }
                    foreach ($class['methods'] as $method) {
                        $methodScore = self::score($terms, ['name' => $method['name'], 'summary' => $method['summary']], self::METHOD_WEIGHTS);
                        if ($methodScore > 0) {
                            $hits[] = ['score' => $methodScore + self::PACKAGE_SHARE * ($packageScore + $classScore), 'type' => 'method', 'class' => $class['name'], 'method' => $method['name'] . $method['signature'], 'description' => $method['summary']] + $base;
                        }
                    }
                }
                foreach ($package['functions'] as $function) {
                    $short = substr($function['name'], (int) strrpos('\\' . $function['name'], '\\'));
                    $functionScore = self::score($terms, ['name' => $short, 'summary' => $function['summary']], self::METHOD_WEIGHTS);
                    if ($functionScore > 0) {
                        $hits[] = ['score' => $functionScore + self::PACKAGE_SHARE * $packageScore, 'type' => 'function', 'class' => '', 'method' => $function['name'] . $function['signature'], 'description' => $function['summary']] + $base;
                    }
                }
            }
        }
        usort($hits, static fn (array $a, array $b): int => [$b['score'], $a['package'], $a['class'], $a['method']] <=> [$a['score'], $b['package'], $b['class'], $b['method']]);

        return array_slice($hits, 0, max(1, $limit));
    }

    /**
     * The hits as text lines for the terminal.
     *
     * @param list<Hit> $hits
     *
     * @return list<string>
     */
    public static function format(array $hits): array
    {
        if ($hits === []) {
            return ['No package matches. Next: ps-module ps find "<term>" (PrestaShop itself), then ps-module find --external "<need>".'];
        }
        $lines = [];
        foreach ($hits as $hit) {
            $what = match ($hit['type']) {
                'package' => $hit['package'],
                'method' => $hit['class'] . '::' . $hit['method'],
                'function' => $hit['method'],
                default => $hit['class'],
            };
            $lines[] = sprintf('%5.1f  %-9s %s', $hit['score'], $hit['type'], $what);
            if ($hit['description'] !== '') {
                $lines[] = '       ' . $hit['description'];
            }
            $lines[] = '       ' . $hit['package'] . ' ' . $hit['version'] . ' (' . $hit['kind'] . '): ' . $hit['require'];
        }

        return $lines;
    }

    /**
     * The score of a set of fields for the terms.
     *
     * @param list<string> $terms
     * @param array<string, string> $fields
     * @param array<string, float> $weights
     */
    private static function score(array $terms, array $fields, array $weights): float
    {
        $score = 0.0;
        foreach ($fields as $field => $text) {
            $words = TextTokens::of($text);
            foreach ($terms as $term) {
                foreach ($words as $word) {
                    if ($word === $term) {
                        $score += $weights[$field];
                    } elseif (strlen($term) >= 4 && str_starts_with($word, $term)) {
                        $score += $weights[$field] / 2;
                    }
                }
            }
        }

        return round($score, 2);
    }

    /**
     * "1.4.2" => "1.4" (the constraint a module requires).
     */
    private static function minor(string $version): string
    {
        return implode('.', array_slice(explode('.', $version), 0, 2));
    }
}
