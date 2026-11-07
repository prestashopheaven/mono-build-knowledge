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
 * ps-module find --external "<need>": the best Packagist results with downloads, latest stable release, license and
 * PHP range, the quick compatibility verdict for the first few, and the best npm results for JavaScript needs.
 *
 * @phpstan-import-type NpmResult from Npm
 * @phpstan-import-type Result from CompatibilityCheck
 *
 * @phpstan-type PhpHit array{name: string, description: string, downloads: int, version: string, license: string, php: string, time: string, verdict: string, reasons: list<string>}
 * @phpstan-type Found array{php: list<PhpHit>, npm: list<NpmResult>}
 */
final class ExternalSearch
{
    /**
     * Uses the clients and the check (null = no verdicts).
     */
    public function __construct(private readonly Packagist $packagist, private readonly Npm $npm, private readonly ?CompatibilityCheck $check)
    {
    }

    /**
     * Searches both registries; the first $checked PHP results get a verdict.
     *
     * @return Found
     */
    public function find(string $need, int $limit = 5, int $checked = 3, bool $npm = true): array
    {
        $php = [];
        foreach ($this->packagist->search($need, $limit) as $n => $result) {
            $latest = Versions::pick($this->packagist->versions($result['name']), null);
            $hit = [
                'name' => $result['name'],
                'description' => $result['description'],
                'downloads' => $result['downloads'],
                'version' => (string) ($latest['version'] ?? ''),
                'license' => Licenses::describe(array_values(array_map('strval', (array) ($latest['license'] ?? [])))),
                'php' => (string) ($latest['require']['php'] ?? ''),
                'time' => substr((string) ($latest['time'] ?? ''), 0, 10),
                'verdict' => '',
                'reasons' => [],
            ];
            if ($this->check !== null && $n < $checked) {
                $verdict = $this->check->check($result['name']);
                $hit['verdict'] = $verdict['verdict'];
                $hit['reasons'] = $verdict['reasons'];
            }
            $php[] = $hit;
        }

        return ['php' => $php, 'npm' => $npm ? $this->npm->search($need, $limit) : []];
    }

    /**
     * The results as text lines.
     *
     * @param Found $found
     *
     * @return list<string>
     */
    public static function format(array $found): array
    {
        $lines = ['Packagist:'];
        foreach ($found['php'] as $hit) {
            $lines[] = sprintf('  %s %s  %s downloads, %s, PHP %s, released %s', $hit['name'], $hit['version'], number_format($hit['downloads']), $hit['license'], $hit['php'] !== '' ? $hit['php'] : 'any', $hit['time'] !== '' ? $hit['time'] : '?');
            if ($hit['description'] !== '') {
                $lines[] = '    ' . $hit['description'];
            }
            if ($hit['verdict'] !== '') {
                $lines[] = '    verdict (quick): ' . $hit['verdict'] . ($hit['reasons'] !== [] ? ' (' . implode(', ', $hit['reasons']) . ')' : '') . '; full check: ps-module external check ' . $hit['name'];
            }
        }
        if ($found['php'] === []) {
            $lines[] = '  nothing found';
        }
        if ($found['npm'] !== []) {
            $lines[] = 'npm:';
            foreach ($found['npm'] as $hit) {
                $lines[] = sprintf('  %s %s  %s downloads/month, %s, released %s', $hit['name'], $hit['version'], number_format($hit['monthly']), $hit['license'] !== '' ? $hit['license'] : 'no license', $hit['date'] !== '' ? $hit['date'] : '?');
                if ($hit['description'] !== '') {
                    $lines[] = '    ' . $hit['description'];
                }
            }
        }

        return $lines;
    }
}
