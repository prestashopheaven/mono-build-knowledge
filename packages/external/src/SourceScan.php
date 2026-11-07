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

use PrestaShopHeaven\Build\PackageIndex\PhpApiScanner;

/**
 * Risk B4 (scoping), statically, on a package's dist archive: the global functions it declares (the files of
 * autoload.files and any other file; a prefixer cannot isolate them, so two modules collide), its function_exists()
 * guards (polyfills), and class names written in strings, which a prefixer misses. Tests/ folders are skipped.
 *
 * @phpstan-type ScanResult array{files: int, globalFunctions: list<string>, namespacedFunctions: list<string>, functionExists: list<string>, stringClasses: list<string>}
 */
final class SourceScan
{
    /** The most examples kept per finding. */
    public const EXAMPLES = 10;

    /**
     * Scans a zip archive given as bytes.
     *
     * @return ScanResult
     *
     * @throws \RuntimeException when the bytes are not a zip archive
     */
    public static function zip(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'phext');
        file_put_contents((string) $file, $bytes);
        $zip = new \ZipArchive();
        if ($zip->open((string) $file) !== true) {
            @unlink((string) $file);
            throw new \RuntimeException('The dist archive is not a zip file.');
        }
        $sources = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with(strtolower($name), '.php') && !preg_match('#(^|/)(tests?|Tests?)/#', $name)) {
                $sources[$name] = (string) $zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink((string) $file);

        return self::sources($sources);
    }

    /**
     * Scans PHP sources (path => code).
     *
     * @param array<string, string> $sources
     *
     * @return ScanResult
     */
    public static function sources(array $sources): array
    {
        $scanner = new PhpApiScanner();
        $result = ['files' => count($sources), 'globalFunctions' => [], 'namespacedFunctions' => [], 'functionExists' => [], 'stringClasses' => []];
        foreach ($sources as $path => $code) {
            $tokens = PhpApiScanner::tokens($code);
            foreach ($scanner->scanTokens($tokens)['functions'] as $function) {
                $result[str_contains($function['name'], '\\') ? 'namespacedFunctions' : 'globalFunctions'][] = $function['name'];
            }
            foreach ($tokens as $n => $t) {
                if (!is_array($t)) {
                    continue;
                }
                if ($t[0] === T_STRING && strtolower($t[1]) === 'function_exists' && ($tokens[$n + 1] ?? null) === '(') {
                    $result['functionExists'][] = "$path:{$t[2]}";
                } elseif ($t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]\\\\{0,2}[A-Z][A-Za-z0-9_]*(\\\\{1,2}[A-Z][A-Za-z0-9_]*)+[\'"]$/', $t[1])) {
                    $result['stringClasses'][] = str_replace('\\\\', '\\', substr($t[1], 1, -1)) . " ($path:{$t[2]})";
                }
            }
        }
        foreach (['globalFunctions', 'namespacedFunctions'] as $key) {
            $result[$key] = array_values(array_unique($result[$key]));
        }

        return $result;
    }
}
