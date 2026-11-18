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
 * Collects the pitfalls where they are written down, so the index is never a stale copy:
 *  - the "Pitfalls" section of the Notes in every module's CONTEXT.md (modules/<repo>/CONTEXT.md);
 *  - the "Valkuilen" / "Pitfalls" sections of the feature recipes (.claude/skills/ps-module/features/<key>/README.md);
 *  - the curated list of this package (pitfalls.json: lessons that belong to no single module, such as tool changes).
 */
final class Collector
{
    /** The headings of a pitfalls section (any level), case-insensitive. */
    public const HEADING = '/^(#{2,4})\s+(Pitfalls|Valkuilen)\b/i';

    /**
     * Every pitfall of the workspace in $workspace (modules/ and .claude/skills/ps-module/features/), plus the curated
     * file.
     *
     * @return list<Pitfall>
     *
     * @throws \RuntimeException when the curated file is broken
     */
    public static function workspace(string $workspace, ?string $curated = null): array
    {
        $pitfalls = [];
        foreach (glob(rtrim($workspace, '/') . '/modules/*/CONTEXT.md') ?: [] as $file) {
            $repo = basename(dirname($file));
            array_push($pitfalls, ...self::fromMarkdown((string) file_get_contents($file), "modules/$repo/CONTEXT.md", $repo));
        }
        foreach (glob(rtrim($workspace, '/') . '/.claude/skills/ps-module/features/*/README.md') ?: [] as $file) {
            $feature = basename(dirname($file));
            array_push($pitfalls, ...self::fromMarkdown((string) file_get_contents($file), "features/$feature/README.md", $feature));
        }
        array_push($pitfalls, ...self::curated($curated ?? dirname(__DIR__) . '/pitfalls.json'));

        return $pitfalls;
    }

    /**
     * The bullets (with their continuation lines) of every pitfalls section in a Markdown text.
     *
     * @return list<Pitfall>
     */
    public static function fromMarkdown(string $markdown, string $source, string $area): array
    {
        $pitfalls = [];
        $level = null;
        $current = null;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if (preg_match('/^(#{1,6})\s/', $line, $m) === 1) {
                if ($current !== null) {
                    $pitfalls[] = new Pitfall($current, $source, $area);
                    $current = null;
                }
                $level = preg_match(self::HEADING, $line, $h) === 1 ? strlen($h[1]) : ($level !== null && strlen($m[1]) > $level ? $level : null);
                continue;
            }
            if ($level === null) {
                continue;
            }
            if (preg_match('/^- (.+)$/', $line, $m) === 1) {
                if ($current !== null) {
                    $pitfalls[] = new Pitfall($current, $source, $area);
                }
                $current = $m[1];
            } elseif ($current !== null && preg_match('/^\s{2,}\S/', $line) === 1) {
                $current .= ' ' . trim($line, " \n\r\t\v\0");
            } elseif ($current !== null) {
                $pitfalls[] = new Pitfall($current, $source, $area);
                $current = null;
            }
        }
        if ($current !== null) {
            $pitfalls[] = new Pitfall($current, $source, $area);
        }

        return $pitfalls;
    }

    /**
     * The curated pitfalls: a JSON list of {text, area} (missing file = none).
     *
     * @return list<Pitfall>
     *
     * @throws \RuntimeException when the file is no JSON list of entries with a text
     */
    public static function curated(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException("$file must be a JSON list of {\"text\", \"area\"} entries.");
        }
        $pitfalls = [];
        foreach ($data as $i => $entry) {
            if (!is_array($entry) || !is_string($entry['text'] ?? null) || !is_string($entry['area'] ?? null)) {
                throw new \RuntimeException("$file: entry $i needs a text and an area.");
            }
            $pitfalls[] = new Pitfall($entry['text'], 'pitfalls.json', $entry['area']);
        }

        return $pitfalls;
    }
}
