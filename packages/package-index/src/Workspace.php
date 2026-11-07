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
 * Finds the PrestaHaven workspace (the folder with libraries/ and modules/) and reads the options of the entry scripts.
 */
final class Workspace
{
    /**
     * The nearest folder at or above one of $starts that has libraries/ and modules/, with "/" separators; null when
     * there is none.
     *
     * @param list<string> $starts
     */
    public static function find(array $starts): ?string
    {
        foreach ($starts as $start) {
            $dir = rtrim(str_replace('\\', '/', (string) (realpath($start) ?: $start)), '/');
            for ($i = 0; $i < 12 && $dir !== '' && $dir !== dirname($dir); ++$i, $dir = dirname($dir)) {
                if (is_dir("$dir/libraries") && is_dir("$dir/modules")) {
                    return $dir;
                }
            }
        }

        return null;
    }

    /**
     * Splits command line arguments into options (--name=value, --flag => '1') and positional arguments.
     *
     * @param list<string> $args
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function options(array $args): array
    {
        $options = [];
        $rest = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/s', $arg, $m)) {
                $options[$m[1]] = $m[2] ?? '1';
            } else {
                $rest[] = $arg;
            }
        }

        return [$options, $rest];
    }
}
