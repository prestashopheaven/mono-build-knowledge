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

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/**
 * Version handling with composer/semver: a constraint check that never throws, and the version Composer would pick for
 * a constraint on our platform (the newest stable version whose PHP constraint allows our lowest PHP, like
 * config.platform.php 8.1 in a module; the newest stable one when none does).
 *
 * @phpstan-import-type VersionData from Packagist
 */
final class Versions
{
    /** Our lowest PHP version (the platform of every module). */
    public const PLATFORM_PHP = '8.1.0';

    /**
     * True when $version satisfies $constraint; false for an invalid version or constraint.
     */
    public static function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * True for a stable version (no dev, alpha, beta or RC).
     */
    public static function isStable(string $version): bool
    {
        return $version !== '' && !str_starts_with($version, 'dev-') && VersionParser::parseStability($version) === 'stable';
    }

    /**
     * The version Composer would install for $constraint (null = any), or null when no stable version fits.
     *
     * @param list<VersionData> $versions
     *
     * @return VersionData|null
     */
    public static function pick(array $versions, ?string $constraint): ?array
    {
        $candidates = array_values(array_filter($versions, static fn (array $v): bool => self::isStable((string) ($v['version'] ?? ''))
            && ($constraint === null || self::satisfies((string) $v['version'], $constraint))));
        usort($candidates, static fn (array $a, array $b): int => version_compare(self::normalized($b), self::normalized($a)));
        foreach ($candidates as $candidate) {
            if (self::satisfies(self::PLATFORM_PHP, (string) ($candidate['require']['php'] ?? '*'))) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * The normalized version of version data ("v1.2.3" => "1.2.3.0").
     *
     * @param VersionData $version
     */
    private static function normalized(array $version): string
    {
        return (string) ($version['version_normalized'] ?? ltrim((string) ($version['version'] ?? '0'), 'vV'));
    }
}
