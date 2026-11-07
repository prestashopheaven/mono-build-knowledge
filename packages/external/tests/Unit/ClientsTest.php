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

namespace PrestaShopHeaven\Build\External\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Build\External\CachedFetcher;
use PrestaShopHeaven\Build\External\Licenses;
use PrestaShopHeaven\Build\External\Npm;
use PrestaShopHeaven\Build\External\Packagist;
use PrestaShopHeaven\Build\External\Register;
use PrestaShopHeaven\Build\External\SourceScan;
use PrestaShopHeaven\Build\External\StreamFetcher;
use PrestaShopHeaven\Build\External\Versions;

/**
 * The fetchers, the Packagist and npm clients (on recorded responses), licenses, versions, the source scan and the
 * register.
 */
final class ClientsTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/external-' . bin2hex(random_bytes(5));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/{,*/}*', GLOB_BRACE) ?: [] as $file) {
            is_file($file) ? unlink($file) : null;
        }
        foreach (glob($this->tmp . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->tmp);
    }

    public function testTheStreamFetcherReadsAndGivesNullOnFailure(): void
    {
        file_put_contents("{$this->tmp}/a.json", '{"a":1}');
        $fetcher = new StreamFetcher(5);
        self::assertSame('{"a":1}', $fetcher->get("{$this->tmp}/a.json"));
        self::assertNull($fetcher->get("{$this->tmp}/missing.json"));
        self::assertSame(404, StreamFetcher::status(['HTTP/1.1 302 Found', 'Location: x', 'HTTP/1.1 404 Not Found', 7]));
        self::assertSame(200, StreamFetcher::status([]));
    }

    public function testTheCachedFetcherKeepsSuccessesOnly(): void
    {
        $inner = new FixtureFetcher();
        $inner->responses['u'] = 'body';
        $cached = new CachedFetcher($inner, "{$this->tmp}/cache", 3600);
        self::assertSame('body', $cached->get('u'));
        self::assertSame('body', $cached->get('u'));
        self::assertSame(1, $inner->requests['u']);
        self::assertNull($cached->get('none'));
        self::assertNull($cached->get('none'));
        self::assertSame(2, $inner->requests['none']);
        $expired = new CachedFetcher($inner, "{$this->tmp}/cache", -1);
        self::assertSame('body', $expired->get('u'));
        self::assertSame(2, $inner->requests['u']);
    }

    public function testPackagistSearchVersionsAdvisoriesInfoAndDist(): void
    {
        $http = new FixtureFetcher();
        $packagist = new Packagist($http);
        $results = $packagist->search('clock', 3);
        self::assertSame(['symfony/clock', 'psr/clock', 'lcobucci/clock'], array_column($results, 'name'));
        self::assertSame(283994294, $results[0]['downloads']);
        $http->responses[Packagist::SEARCH_URL . 'broken&per_page=2'] = '{"results":["x",{"name":"a/b"}]}';
        self::assertSame([['name' => 'a/b', 'description' => '', 'downloads' => 0, 'favers' => 0, 'url' => '']], $packagist->search('broken', 2));
        self::assertSame([], $packagist->search('nothing', 1));

        $versions = $packagist->versions('Symfony/Clock');
        self::assertSame('v8.2.0-BETA1', $versions[0]['version']);
        self::assertSame('symfony/clock', $versions[5]['name'], 'expanded from the minified form');
        self::assertSame(1, $http->requests[Packagist::P2_URL . 'symfony/clock.json'], 'fetched once');
        self::assertSame([], $packagist->versions('no/such'));
        self::assertSame([], $packagist->advisories('no/such'));

        $http->package('a/plain', [['version' => '1.0.0', 'dist' => ['url' => 'zip://a']]], [['advisoryId' => 'X', 'affectedVersions' => '<2.0'], 'broken'], ['abandoned' => 'b/new', 'downloads' => ['total' => 5, 'monthly' => 2], 'repository' => 'r']);
        self::assertSame('1.0.0', $packagist->versions('a/plain')[0]['version']);
        self::assertSame([['advisoryId' => 'X', 'affectedVersions' => '<2.0']], $packagist->advisories('a/plain'));
        self::assertSame(['abandoned' => 'b/new', 'downloads' => 5, 'monthly' => 2, 'repository' => 'r'], $packagist->info('a/plain'));
        $http->package('a/gone', [['version' => '1.0.0']], [], ['abandoned' => true]);
        self::assertTrue($packagist->info('a/gone')['abandoned'] ?? null);
        self::assertNull($packagist->info('no/such'));
        $http->responses['zip://a'] = 'ZIP';
        self::assertSame('ZIP', $packagist->dist(['dist' => ['url' => 'zip://a']]));
        self::assertNull($packagist->dist(['version' => '1.0.0']));
    }

    public function testExpandUndoesTheMinifiedForm(): void
    {
        self::assertSame([
            ['name' => 'a', 'version' => '2', 'x' => 1],
            ['name' => 'a', 'version' => '1', 'x' => 1, 'y' => 2],
            ['name' => 'a', 'version' => '0', 'y' => 2],
        ], Packagist::expand([['name' => 'a', 'version' => '2', 'x' => 1], ['version' => '1', 'y' => 2], ['version' => '0', 'x' => '__unset']]));
    }

    public function testNpmSearch(): void
    {
        $http = new FixtureFetcher();
        $npm = new Npm($http);
        $results = $npm->search('clock', 3);
        self::assertSame(['clock', 'react-clock'], array_column($results, 'name'));
        self::assertSame(['1.0.2', 'ISC', 1756, '2017-07-13'], [$results[0]['version'], $results[0]['license'], $results[0]['monthly'], $results[0]['date']]);
        $http->responses[Npm::SEARCH_URL . 'odd&size=1'] = '{"objects":["x",{"package":{}},{"package":{"name":"p","license":{"type":"MIT"}}}]}';
        self::assertSame([['name' => 'p', 'version' => '', 'description' => '', 'license' => '', 'monthly' => 0, 'date' => '', 'url' => '']], $npm->search('odd', 1));
        self::assertSame([], $npm->search('offline', 1));
    }

    public function testLicensesFollowTheAllowlist(): void
    {
        self::assertTrue(Licenses::allowed(['MIT']));
        self::assertTrue(Licenses::allowed(['GPL-2.0-only', 'bsd-3-clause']));
        self::assertTrue(Licenses::allowed(['(GPL-3.0 or Apache-2.0)']));
        self::assertTrue(Licenses::allowed(['LGPL-2.1 OR MIT']));
        self::assertFalse(Licenses::allowed(['GPL-2.0-or-later']));
        self::assertFalse(Licenses::allowed(['proprietary']));
        self::assertFalse(Licenses::allowed([]));
        self::assertSame('none', Licenses::describe([]));
        self::assertSame('MIT OR GPL-2.0', Licenses::describe(['MIT', 'GPL-2.0']));
    }

    public function testVersionsPickWhatComposerWouldInstall(): void
    {
        self::assertTrue(Versions::satisfies('1.2.0', '^1.0'));
        self::assertFalse(Versions::satisfies('1.2.0', 'not a constraint !!'));
        self::assertTrue(Versions::isStable('v1.2.3'));
        self::assertFalse(Versions::isStable('2.0.0-beta1'));
        self::assertFalse(Versions::isStable('dev-main'));
        self::assertFalse(Versions::isStable(''));
        $versions = [
            ['version' => '3.0.0', 'require' => ['php' => '>=8.3']],
            ['version' => '2.1.0', 'version_normalized' => '2.1.0.0', 'require' => ['php' => '>=8.1']],
            ['version' => '2.0.0'],
            ['version' => '4.0.0-RC1'],
        ];
        self::assertSame('2.1.0', Versions::pick($versions, null)['version'] ?? null, 'the newest one PHP 8.1 can install');
        self::assertSame('3.0.0', Versions::pick($versions, '^3.0')['version'] ?? null, 'none fits PHP 8.1: the newest');
        self::assertSame('2.0.0', Versions::pick($versions, '2.0.0')['version'] ?? null);
        self::assertNull(Versions::pick($versions, '^5.0'));
    }

    public function testTheSourceScanFindsWhatScopingMisses(): void
    {
        $zip = FixtureFetcher::zip([
            'pkg/bootstrap.php' => "<?php\nif (!function_exists('mb_x')) {\n    function mb_x() {}\n}\n",
            'pkg/src/Thing.php' => "<?php\nnamespace Pkg;\nfunction helper() {}\nclass Thing { public function c() { return ['Pkg\\\\Other', \"\\\\Pkg\\\\Third\", 'plain', 'Not\\\\lower']; } }\n",
            'pkg/tests/ThingTest.php' => "<?php\nfunction test_only() {}\n",
            'pkg/README.md' => 'readme',
        ]);
        $scan = SourceScan::zip($zip);
        self::assertSame(2, $scan['files']);
        self::assertSame(['mb_x'], $scan['globalFunctions']);
        self::assertSame(['Pkg\helper'], $scan['namespacedFunctions']);
        self::assertSame(['pkg/bootstrap.php:2'], $scan['functionExists']);
        self::assertSame(['Pkg\Other (pkg/src/Thing.php:4)', '\Pkg\Third (pkg/src/Thing.php:4)'], $scan['stringClasses']);
        $this->expectException(\RuntimeException::class);
        SourceScan::zip('not a zip');
    }

    public function testTheRegisterRowAndRecord(): void
    {
        $pass = ['package' => 'psr/clock', 'version' => '1.0.0', 'constraint' => '', 'prestashop' => [], 'deep' => true, 'risks' => [['risk' => 'B1', 'level' => 'ok', 'message' => 'x']], 'dependencies' => [], 'verdict' => 'use, through a wrapper', 'reasons' => []];
        self::assertSame('| 2026-11-07 | `psr/clock` | 1.0.0 | runtime-clock | use, through a wrapper | automatic check B1-B8 passed; B5 manual | runtime-clock |', Register::row($pass, '2026-11-07', 'runtime-clock', 'runtime-clock'));
        $fail = ['constraint' => '^6.4', 'deep' => false, 'verdict' => 'write our own', 'risks' => [['risk' => 'B4', 'level' => 'fail', 'message' => 'a | b']]] + $pass;
        self::assertSame('| 2026-11-07 | `psr/clock` | `^6.4` | – | write our own | B4: a \| b | – |', Register::row($fail, '2026-11-07'));
        self::assertStringContainsString('passed (quick)', Register::row(['deep' => false] + $pass, 'd'));

        $file = "{$this->tmp}/external.md";
        file_put_contents($file, "# X\n\n" . Register::HEADING . "\n\nText.\n\n| Date | Package |\n|---|---|\n| old | row |\n\nAfter.\n");
        Register::record($file, '| new | row |');
        self::assertStringContainsString("|---|---|\n| new | row |\n| old | row |\n", (string) file_get_contents($file));
        $this->expectException(\RuntimeException::class);
        Register::record("{$this->tmp}/none.md", '| x |');
    }
}
