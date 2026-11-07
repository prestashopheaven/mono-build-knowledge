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

use PrestaShopHeaven\Build\PrestashopIndex\SymbolIndex;

/**
 * ps-module external check <package>[:<constraint>]: the automatic part of risks B1-B8 (docs/packages/external.md
 * section 2) for one Composer package and its whole dependency tree:
 *
 * - B1 the PHP constraint of the package and of every dependency it ships allows PHP 8.1 to 8.5;
 * - B2/B3 per indexed PrestaShop release (the vendor list of build-prestashop-index): a dependency PrestaShop ships in
 *   a fitting version in every release is used from PrestaShop; otherwise it is shipped scoped, which a shared
 *   interface package (psr/container, psr/log, the Symfony contracts, ...) cannot be;
 * - B4 autoload.files with global functions (fail), function_exists() guards and class names in strings (warnings),
 *   from the dist archives in a deep check (metadata only in a quick check);
 * - B5 the validator on a test zip: a manual step for now;
 * - B6 the license of the package and every shipped dependency is on the allowlist of D20;
 * - B7 abandoned, the last release older than two years, security advisories for the chosen versions;
 * - B8 the size of the dist archives that would be shipped (deep check).
 *
 * The verdict is "use, through a wrapper" when nothing fails, else "write our own" with the failed risk numbers.
 *
 * @phpstan-import-type VersionData from Packagist
 *
 * @phpstan-type Risk array{risk: string, level: string, message: string}
 * @phpstan-type Dependency array{name: string, version: string, constraint: string, requiredBy: list<string>, license: string, ships: string}
 * @phpstan-type Result array{package: string, version: string, constraint: string, prestashop: list<string>, deep: bool, risks: list<Risk>, dependencies: list<Dependency>, verdict: string, reasons: list<string>}
 */
final class CompatibilityCheck
{
    /** The PHP versions of our range (B1). */
    public const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    /** Packages whose classes or interfaces are exchanged with the PrestaShop core: never scoped (B3, PKG-19). */
    public const SHARED = [
        'psr/container', 'psr/log', 'psr/event-dispatcher', 'psr/cache', 'psr/simple-cache',
        'symfony/contracts', 'symfony/service-contracts', 'symfony/event-dispatcher-contracts', 'symfony/translation-contracts',
        'symfony/cache-contracts', 'symfony/deprecation-contracts', 'doctrine/persistence', 'doctrine/dbal', 'doctrine/orm',
        'doctrine/event-manager', 'twig/twig',
    ];

    /** Requirements that are not packages. */
    private const PLATFORM = '/^(php(-64bit|-ipv6|-zts|-debug)?|hhvm|ext-.+|lib-.+|composer-plugin-api|composer-runtime-api|composer)$/';

    /** The most packages a dependency tree may have before the check stops resolving. */
    public const MAX_PACKAGES = 40;

    /** Above this size in bytes the shipped archives get a warning (B8). */
    public const SIZE_WARN = 5 * 1048576;

    /** Above this size in bytes the shipped archives fail (B8; the validator accepts at most 30 MB per zip). */
    public const SIZE_FAIL = 20 * 1048576;

    /** @var list<Risk> */
    private array $risks = [];

    /**
     * Sets up the check against the PrestaShop releases from $psMin to $psMax of the index; $deep downloads and scans
     * the dist archives (B4, B8).
     */
    public function __construct(
        private readonly Packagist $packagist,
        private readonly SymbolIndex $prestashop,
        private readonly bool $deep,
        private readonly \DateTimeImmutable $now,
        private readonly ?string $psMin = null,
        private readonly ?string $psMax = null,
    ) {
    }

    /**
     * Checks "vendor/name" or "vendor/name:<constraint>".
     *
     * @return Result
     */
    public function check(string $spec): array
    {
        $this->risks = [];
        [$name, $constraint] = array_pad(explode(':', trim($spec), 2), 2, null);
        $name = strtolower((string) $name);
        $releases = array_map(fn (int $n): string => $this->prestashop->versions()[$n], $this->prestashop->positionsInRange($this->psMin, $this->psMax));
        $root = Versions::pick($this->packagist->versions($name), $constraint);
        $result = ['package' => $name, 'version' => '', 'constraint' => (string) $constraint, 'prestashop' => $releases, 'deep' => $this->deep, 'risks' => [], 'dependencies' => [], 'verdict' => 'write our own', 'reasons' => []];
        if ($root === null) {
            $this->risk('B7', 'fail', $constraint === null ? "$name has no stable release on Packagist." : "$name has no stable release that fits $constraint.");

            return $this->finish($result);
        }
        $version = (string) $root['version'];
        $result['version'] = $version;
        $tree = $this->resolve($root);
        $packages = ['root' => ['name' => $name, 'data' => $root, 'constraint' => $constraint ?? self::caret($version), 'requiredBy' => []]] + $tree;
        $shipped = [];
        foreach ($packages as $key => $node) {
            $ships = $this->prestashopCopy($node['name'], $node['constraint'], $releases, $key === 'root');
            $result['dependencies'][] = [
                'name' => $node['name'],
                'version' => (string) ($node['data']['version'] ?? '?'),
                'constraint' => $node['constraint'],
                'requiredBy' => $node['requiredBy'],
                'license' => Licenses::describe(array_values(array_map('strval', (array) ($node['data']['license'] ?? [])))),
                'ships' => $ships,
            ];
            if ($ships === 'scoped' && $node['data'] !== null) {
                $shipped[$node['name']] = $node['data'];
            }
        }
        $this->php($shipped);
        $this->scoping($shipped);
        $this->risk('B5', 'manual', 'Run our validator pre-check and the online validator on a test module zip that ships the package (ps-module validate); not automated yet.');
        $this->licenses($shipped + [$name => $root]);
        $this->health($name, $root, $shipped);
        $this->size($shipped);

        return $this->finish($result);
    }

    /**
     * The check result as text lines.
     *
     * @param Result $result
     *
     * @return list<string>
     */
    public static function format(array $result): array
    {
        $lines = ["{$result['package']} " . ($result['version'] !== '' ? $result['version'] : '(no version)') . ' against PrestaShop ' . implode(', ', $result['prestashop']) . ($result['deep'] ? '' : ' (quick check: metadata only)')];
        foreach ($result['dependencies'] as $dependency) {
            $lines[] = sprintf('  %s %s (%s, %s)%s', $dependency['name'], $dependency['version'], $dependency['license'], $dependency['ships'] === 'prestashop' ? "PrestaShop's copy" : 'shipped scoped', $dependency['requiredBy'] !== [] ? ' <- ' . implode(', ', $dependency['requiredBy']) : '');
        }
        foreach ($result['risks'] as $risk) {
            $lines[] = sprintf('%-6s %s %s', strtoupper($risk['level']), $risk['risk'], $risk['message']);
        }
        $lines[] = 'Verdict: ' . $result['verdict'] . ($result['reasons'] !== [] ? ' (' . implode(', ', $result['reasons']) . ')' : '');

        return $lines;
    }

    /**
     * The dependency tree below a version, breadth first: name => the version Composer would pick, the constraints
     * and who requires it. A package without a fitting stable version gets data null (a B7 failure).
     *
     * @param VersionData $root
     *
     * @return array<string, array{name: string, data: VersionData|null, constraint: string, requiredBy: list<string>}>
     */
    private function resolve(array $root): array
    {
        $tree = [];
        $queue = [[(string) $root['name'], $root]];
        while ($queue !== []) {
            [$parent, $data] = array_shift($queue);
            foreach ((array) ($data['require'] ?? []) as $dependency => $constraint) {
                $dependency = strtolower((string) $dependency);
                if (preg_match(self::PLATFORM, $dependency)) {
                    continue;
                }
                if (isset($tree[$dependency])) {
                    $tree[$dependency]['requiredBy'][] = $parent;
                    $tree[$dependency]['constraint'] .= ', ' . $constraint;
                    continue;
                }
                if (count($tree) >= self::MAX_PACKAGES) {
                    $this->risk('B8', 'warn', 'The dependency tree has more than ' . self::MAX_PACKAGES . ' packages; the rest was not checked.');
                    break 2;
                }
                $picked = Versions::pick($this->packagist->versions($dependency), (string) $constraint);
                $tree[$dependency] = ['name' => $dependency, 'data' => $picked, 'constraint' => (string) $constraint, 'requiredBy' => [$parent]];
                if ($picked === null) {
                    $this->risk('B7', 'fail', "$dependency ($constraint, required by $parent) has no stable release on Packagist that fits.");
                    continue;
                }
                $queue[] = [$dependency, $picked];
            }
        }

        return $tree;
    }

    /**
     * B2/B3 for one package: "prestashop" when every release of the range ships a version that fits the constraint
     * (use PrestaShop's copy), else "scoped"; records the clash or the shared-interface failure.
     *
     * @param list<string> $releases
     */
    private function prestashopCopy(string $name, string $constraint, array $releases, bool $isRoot): string
    {
        $shipped = array_intersect_key($this->prestashop->vendorVersions($name), array_flip($releases));
        $fitting = array_filter($shipped, static fn (string $version): bool => Versions::satisfies($version, $constraint));
        if ($releases !== [] && count($fitting) === count($releases)) {
            $this->risk('B2', 'ok', "PrestaShop ships $name in a version that fits $constraint in every release: use PrestaShop's copy, do not scope or ship it (PKG-19).");

            return 'prestashop';
        }
        $per = implode(', ', array_map(static fn (string $release): string => "$release: " . ($shipped[$release] ?? 'not shipped'), $releases));
        if (in_array($name, self::SHARED, true) && $shipped !== []) {
            $this->risk('B3', 'fail', "$name is a shared interface package and must be PrestaShop's own copy, but it does not fit $constraint in every release ($per).");

            return 'scoped';
        }
        if ($shipped !== []) {
            $this->risk('B2', 'warn', ($isRoot ? '' : 'Dependency ') . "$name: PrestaShop ships a version that does not fit $constraint in every release ($per): ours is shipped scoped, which needs B4 to pass.");
        }

        return 'scoped';
    }

    /**
     * B1: the PHP constraint of every shipped package allows every PHP version of our range.
     *
     * @param array<string, VersionData> $shipped
     */
    private function php(array $shipped): void
    {
        $failed = false;
        foreach ($shipped as $name => $data) {
            $constraint = (string) ($data['require']['php'] ?? '');
            if ($constraint === '') {
                continue;
            }
            $missing = array_values(array_filter(self::PHP_VERSIONS, static fn (string $php): bool => !Versions::satisfies("$php.0", $constraint) || !Versions::satisfies("$php.99", $constraint)));
            if ($missing !== []) {
                $failed = true;
                $this->risk('B1', 'fail', "$name {$data['version']} requires PHP $constraint, which leaves out PHP " . implode(', ', $missing) . '.');
            }
        }
        if (!$failed) {
            $this->risk('B1', 'ok', 'Every shipped package allows PHP ' . self::PHP_VERSIONS[0] . ' to ' . self::PHP_VERSIONS[count(self::PHP_VERSIONS) - 1] . '.');
        }
    }

    /**
     * B4: what a prefixer cannot isolate, for every shipped package.
     *
     * @param array<string, VersionData> $shipped
     */
    private function scoping(array $shipped): void
    {
        $level = 'ok';
        foreach ($shipped as $name => $data) {
            $files = array_values(array_map('strval', (array) ($data['autoload']['files'] ?? [])));
            if (!$this->deep) {
                if ($files !== []) {
                    $polyfill = str_contains($name, 'polyfill');
                    $this->risk('B4', $polyfill ? 'fail' : 'warn', "$name loads autoload.files (" . implode(', ', $files) . ')' . ($polyfill ? ': a polyfill declares global functions, which scoping cannot isolate.' : '; run the deep check to see whether they declare global functions.'));
                    $level = $polyfill ? 'fail' : 'warn';
                }
                continue;
            }
            $archive = $this->packagist->dist($data);
            if ($archive === null) {
                $this->risk('B4', 'warn', "$name: the dist archive could not be downloaded, so its source was not scanned.");
                continue;
            }
            try {
                $scan = SourceScan::zip($archive);
            } catch (\RuntimeException $e) {
                $this->risk('B4', 'warn', "$name: " . $e->getMessage());
                continue;
            }
            if ($scan['globalFunctions'] !== []) {
                $this->risk('B4', 'fail', "$name declares global functions (" . implode(', ', array_slice($scan['globalFunctions'], 0, SourceScan::EXAMPLES)) . ($files !== [] ? '; autoload.files ' . implode(', ', $files) : '') . '): scoping cannot isolate them, so two modules collide.');
                $level = 'fail';
            } elseif ($files !== []) {
                $this->risk('B4', 'warn', "$name loads autoload.files (" . implode(', ', $files) . ') with namespaced functions only (' . implode(', ', array_slice($scan['namespacedFunctions'], 0, SourceScan::EXAMPLES)) . '): the prefixer renames them; check the scoped build.');
            }
            if ($scan['functionExists'] !== []) {
                $this->risk('B4', 'warn', "$name calls function_exists() " . count($scan['functionExists']) . ' time(s) (' . implode(', ', array_slice($scan['functionExists'], 0, 3)) . '): check that the guarded functions are prefixed too.');
            }
            if ($scan['stringClasses'] !== []) {
                $this->risk('B4', 'warn', "$name names " . count($scan['stringClasses']) . ' class(es) in strings (' . implode(', ', array_slice($scan['stringClasses'], 0, 3)) . '): the prefixer may miss them.');
            }
        }
        if ($level === 'ok' && array_filter($this->risks, static fn (array $r): bool => $r['risk'] === 'B4') === []) {
            $this->risk('B4', 'ok', $shipped === [] ? 'Nothing is shipped: no scoping needed.' : 'No global functions, autoload.files or string class names found' . ($this->deep ? '.' : ' in the metadata.'));
        }
    }

    /**
     * B6: the license of the package and every shipped dependency.
     *
     * @param array<string, VersionData> $packages
     */
    private function licenses(array $packages): void
    {
        $failed = false;
        foreach ($packages as $name => $data) {
            $licenses = array_values(array_map('strval', (array) ($data['license'] ?? [])));
            if (!Licenses::allowed($licenses)) {
                $failed = true;
                $this->risk('B6', 'fail', "$name is licensed " . Licenses::describe($licenses) . ', which is not on the allowlist (' . implode(', ', Licenses::ALLOWED) . ', D20).');
            }
        }
        if (!$failed) {
            $this->risk('B6', 'ok', 'Every license is on the allowlist (D20).');
        }
    }

    /**
     * B7: abandoned, the last release, security advisories.
     *
     * @param VersionData $root
     * @param array<string, VersionData> $shipped
     */
    private function health(string $name, array $root, array $shipped): void
    {
        $before = count($this->risks);
        $info = $this->packagist->info($name);
        if ($info !== null && $info['abandoned'] !== false) {
            $this->risk('B7', 'fail', "$name is abandoned" . (is_string($info['abandoned']) && $info['abandoned'] !== '' ? ", use {$info['abandoned']} instead." : '.'));
        }
        $newest = Versions::pick($this->packagist->versions($name), null);
        $time = strtotime((string) ($newest['time'] ?? $root['time'] ?? ''));
        if ($time !== false && $time < $this->now->modify('-2 years')->getTimestamp()) {
            $this->risk('B7', 'warn', "The last release of $name is from " . date('Y-m-d', $time) . ', more than two years ago: fine only when it is small and finished.');
        }
        foreach ([$name => $root] + $shipped as $package => $data) {
            if (isset($data['abandoned']) && $data['abandoned'] !== false && $package !== $name) {
                $this->risk('B7', 'fail', "Dependency $package is abandoned.");
            }
            foreach ($this->packagist->advisories($package) as $advisory) {
                if (Versions::satisfies((string) $data['version'], $advisory['affectedVersions'])) {
                    $this->risk('B7', 'fail', "$package {$data['version']} has security advisory {$advisory['advisoryId']} (affected: {$advisory['affectedVersions']}).");
                }
            }
        }
        if (count($this->risks) === $before) {
            $this->risk('B7', 'ok', 'Not abandoned, released in the last two years, no security advisory for the chosen versions' . ($info === null ? ' (the package page could not be read)' : ", {$info['monthly']} downloads a month") . '.');
        }
    }

    /**
     * B8: the size of the archives that would be shipped (deep check only).
     *
     * @param array<string, VersionData> $shipped
     */
    private function size(array $shipped): void
    {
        if (!$this->deep) {
            $this->risk('B8', 'manual', 'Size not measured in a quick check (run it with --deep).');

            return;
        }
        $bytes = 0;
        foreach ($shipped as $data) {
            $bytes += strlen((string) $this->packagist->dist($data));
        }
        $size = $bytes >= 1048576 ? round($bytes / 1048576, 2) . ' MB' : round($bytes / 1024, 1) . ' KB';
        $level = $bytes > self::SIZE_FAIL ? 'fail' : ($bytes > self::SIZE_WARN ? 'warn' : 'ok');
        $this->risk('B8', $level, "The shipped archives are $size (zipped) for " . count($shipped) . ' package(s).');
    }

    /**
     * Adds a risk finding.
     */
    private function risk(string $risk, string $level, string $message): void
    {
        $this->risks[] = ['risk' => $risk, 'level' => $level, 'message' => $message];
    }

    /**
     * Sorts the findings by risk number and sets the verdict.
     *
     * @param Result $result
     *
     * @return Result
     */
    private function finish(array $result): array
    {
        $risks = $this->risks;
        usort($risks, static fn (array $a, array $b): int => strcmp($a['risk'], $b['risk']));
        $result['risks'] = $risks;
        $result['reasons'] = array_values(array_unique(array_map(static fn (array $r): string => $r['risk'], array_filter($risks, static fn (array $r): bool => $r['level'] === 'fail'))));
        $usesPrestashop = ($result['dependencies'][0]['ships'] ?? '') === 'prestashop';
        $result['verdict'] = $result['reasons'] !== [] ? 'write our own' : 'use, through a wrapper' . ($usesPrestashop ? " (PrestaShop's own copy)" : '');

        return $result;
    }

    /**
     * "^X.Y" for a version ("v1.2.3" => "^1.2").
     */
    private static function caret(string $version): string
    {
        $parts = explode('.', ltrim($version, 'vV'));

        return '^' . $parts[0] . '.' . ($parts[1] ?? '0');
    }
}
