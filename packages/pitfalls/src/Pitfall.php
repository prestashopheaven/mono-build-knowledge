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
 * One known pitfall: its text (a Markdown bullet without the dash), where it was written down (a module's CONTEXT.md, a
 * feature recipe or the curated list) and the area it belongs to (the module or feature name).
 */
final class Pitfall
{
    /**
     * A pitfall; $text is trimmed and must not be empty.
     *
     * @throws \InvalidArgumentException when $text or $source is empty
     */
    public function __construct(public readonly string $text, public readonly string $source, public readonly string $area)
    {
        if (trim($text, " \n\r\t\v\0") === '' || trim($source, " \n\r\t\v\0") === '') {
            throw new \InvalidArgumentException('A pitfall needs a text and a source.');
        }
    }

    /**
     * The first sentence or line of the text, at most 120 characters: the title in search results.
     */
    public function title(): string
    {
        $line = (string) preg_replace('/\s+/', ' ', $this->text);
        $line = (string) preg_replace('/^\*\*(.+?):?\*\*:?\s*/', '$1: ', $line);
        $end = strpos($line, '. ');
        $title = $end === false ? $line : substr($line, 0, $end + 1);

        return mb_strlen($title) > 120 ? rtrim(mb_substr($title, 0, 119), " \n\r\t\v\0") . '…' : $title;
    }

    /**
     * The entry as an array, for --json.
     *
     * @return array{text: string, source: string, area: string}
     */
    public function toArray(): array
    {
        return ['text' => $this->text, 'source' => $this->source, 'area' => $this->area];
    }
}
