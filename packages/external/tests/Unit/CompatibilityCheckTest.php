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
use PrestaShopHeaven\Build\External\Cli;
use PrestaShopHeaven\Build\External\CompatibilityCheck;
use PrestaShopHeaven\Build\External\ExternalSearch;
use PrestaShopHeaven\Build\External\Npm;
use PrestaShopHeaven\Build\External\Packagist;
use PrestaShopHeaven\Build\External\StreamFetcher;

/**
 * The compatibility check of risks B1-B8, the external search and the commands, on recorded and defined packages.
 */
final class CompatibilityCheckTest extends TestCase
{
    private FixtureFetcher $http;

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->http = new FixtureFetcher();
        $this->now = new \DateTimeImmutable('2026-11-07');
        $recent = '2026-06-01T00:00:00+00:00';
        $clean = FixtureFetcher::zip(['p/src/A.php' => "<?php\nnamespace P;\nclass A {}\n"]);
        $this->http->responses['zip://clean'] = $clean;
        $this->http->package('demo/good', [
            ['version' => '1.2.0', 'license' => ['MIT'], 'time' => $recent, 'require' => ['php' => '>=8.1', 'ext-json' => '*', 'psr/log' => '^1.1 || ^3.0', 'demo/dep' => '^1.0', 'symfony/polyfill-mbstring' => '^1.27'], 'dist' => ['url' => 'zip://clean']],
            ['version' => '1.1.0', 'license' => ['MIT'], 'time' => $recent, 'require' => ['php' => '>=8.1']],
        ], [], ['downloads' => ['total' => 10, 'monthly' => 3]], true);
        $this->http->package('demo/dep', [['version' => '1.0.1', 'license' => ['BSD-3-Clause'], 'time' => $recent, 'dist' => ['url' => 'zip://clean']]]);
        $this->http->package('psr/log', [['version' => '3.0.2', 'license' => ['MIT']], ['version' => '1.1.4', 'license' => ['MIT']]]);
        $this->http->package('psr/container', [['version' => '2.0.2', 'license' => ['MIT']]]);
        $this->http->package('symfony/polyfill-mbstring', [['version' => 'v1.31.0', 'license' => ['MIT'], 'autoload' => ['files' => ['bootstrap.php']]]]);
    }

    public function testAPackageThatFitsIsUsedThroughAWrapper(): void
    {
        $result = $this->check(true)->check('demo/good');
        self::assertSame(['demo/good', '1.2.0', '', ['8.1.0', '9.0.0'], true], [$result['package'], $result['version'], $result['constraint'], $result['prestashop'], $result['deep']]);
        self::assertSame('use, through a wrapper', $result['verdict']);
        self::assertSame([], $result['reasons']);
        $ships = array_column($result['dependencies'], 'ships', 'name');
        self::assertSame(['demo/good' => 'scoped', 'psr/log' => 'prestashop', 'demo/dep' => 'scoped', 'symfony/polyfill-mbstring' => 'prestashop'], $ships);
        $risks = array_map(static fn (array $r): string => "{$r['risk']} {$r['level']}", $result['risks']);
        self::assertSame(['B1 ok', 'B2 ok', 'B2 ok', 'B4 ok', 'B5 manual', 'B6 ok', 'B7 ok', 'B8 ok'], $risks);
        $text = implode("\n", CompatibilityCheck::format($result));
        self::assertStringContainsString('demo/good 1.2.0 against PrestaShop 8.1.0, 9.0.0', $text);
        self::assertStringContainsString("  psr/log 3.0.2 (MIT, PrestaShop's copy) <- demo/good", $text);
        self::assertStringContainsString('3 downloads a month', $text);
        self::assertStringContainsString('KB (zipped) for 2 package(s)', $text);
        self::assertStringEndsWith('Verdict: use, through a wrapper', $text);
    }

    public function testAPackagePrestashopShipsEverywhereIsUsedFromPrestashop(): void
    {
        $this->http->package('guzzlehttp/guzzle', [['version' => '7.9.3', 'license' => ['MIT'], 'time' => '2026-01-01T00:00:00+00:00', 'require' => ['php' => '^7.2.5 || ^8.0']]]);
        $result = $this->check(false)->check('guzzlehttp/guzzle:^7.5');
        self::assertSame("use, through a wrapper (PrestaShop's own copy)", $result['verdict']);
        self::assertSame('^7.5', $result['constraint']);
        self::assertStringContainsString('Nothing is shipped', implode("\n", array_column($result['risks'], 'message')));
        self::assertStringContainsString('quick check: metadata only', CompatibilityCheck::format($result)[0]);
    }

    public function testTheRecordedClockPackages(): void
    {
        $psr = $this->check(false)->check('psr/clock');
        self::assertSame(['1.0.0', 'use, through a wrapper'], [$psr['version'], $psr['verdict']]);
        self::assertStringContainsString('8.1.0: not shipped, 9.0.0: 1.0.0', implode("\n", array_column($psr['risks'], 'message')));
        self::assertStringContainsString('more than two years ago', implode("\n", array_column($psr['risks'], 'message')));

        $symfony = $this->check(false)->check('symfony/clock');
        self::assertSame('v6.4.48', $symfony['version'], 'the newest release PHP 8.1 can install');
        self::assertSame(['B4'], $symfony['reasons']);
        $messages = implode("\n", array_column($symfony['risks'], 'message'));
        self::assertStringContainsString('symfony/polyfill-php83 loads autoload.files (bootstrap.php): a polyfill declares global functions', $messages);
        self::assertStringContainsString('symfony/clock loads autoload.files (Resources/now.php); run the deep check', $messages);
        self::assertStringContainsString('Size not measured in a quick check', $messages);
    }

    public function testEveryRiskThatFails(): void
    {
        $this->http->package('demo/bad', [['version' => '2.0.0', 'license' => ['GPL-3.0-only'], 'time' => '2020-01-01T00:00:00+00:00', 'require' => ['php' => '~8.1.0', 'psr/container' => '^2.0', 'demo/ghost' => '^1.0', 'demo/old-dep' => '^1.0', 'demo/vuln' => '^1.0']]], [['advisoryId' => 'PKSA-1', 'affectedVersions' => '>=2.0,<2.1']], ['abandoned' => 'demo/better']);
        $this->http->package('demo/old-dep', [['version' => '1.0.0', 'license' => [], 'abandoned' => true, 'require' => ['php' => '>=5.3', 'demo/vuln' => '^1.0']]]);
        $this->http->package('demo/vuln', [['version' => '1.0.0', 'license' => ['MIT']]], [['advisoryId' => 'PKSA-2', 'affectedVersions' => '<1.1']]);
        $result = $this->check(false)->check('demo/bad');
        self::assertSame('write our own', $result['verdict']);
        self::assertSame(['B1', 'B3', 'B6', 'B7'], $result['reasons']);
        $messages = implode("\n", array_column($result['risks'], 'message'));
        foreach ([
            'requires PHP ~8.1.0, which leaves out PHP 8.2, 8.3, 8.4, 8.5',
            'psr/container is a shared interface package and must be PrestaShop\'s own copy, but it does not fit ^2.0 in every release (8.1.0: 1.1.2, 9.0.0: 2.0.2)',
            'demo/ghost (^1.0, required by demo/bad) has no stable release on Packagist that fits',
            'demo/bad is licensed GPL-3.0-only',
            'demo/old-dep is licensed none',
            'demo/bad is abandoned, use demo/better instead.',
            'The last release of demo/bad is from 2020-01-01',
            'Dependency demo/old-dep is abandoned.',
            'demo/bad 2.0.0 has security advisory PKSA-1',
            'demo/vuln 1.0.0 has security advisory PKSA-2',
        ] as $expected) {
            self::assertStringContainsString($expected, $messages);
        }
        self::assertSame(['demo/bad', 'demo/old-dep'], array_column($result['dependencies'], 'requiredBy', 'name')['demo/vuln']);
    }

    public function testADeepCheckScansTheArchives(): void
    {
        $this->http->package('demo/poly', [['version' => '1.0.0', 'license' => ['MIT'], 'time' => '2026-01-01T00:00:00+00:00', 'autoload' => ['files' => ['src/functions.php']], 'require' => ['demo/nsfunc' => '^1.0', 'demo/nodist' => '^1.0', 'demo/badzip' => '^1.0'], 'dist' => ['url' => 'zip://poly']]]);
        $this->http->package('demo/nsfunc', [['version' => '1.0.0', 'license' => ['MIT'], 'autoload' => ['files' => ['src/fn.php']], 'dist' => ['url' => 'zip://nsfunc']]]);
        $this->http->package('demo/nodist', [['version' => '1.0.0', 'license' => ['MIT']]]);
        $this->http->package('demo/badzip', [['version' => '1.0.0', 'license' => ['MIT'], 'dist' => ['url' => 'zip://bad']]]);
        $this->http->responses['zip://poly'] = FixtureFetcher::zip(['poly/src/functions.php' => "<?php\nfunction dd() {}\n"]);
        $this->http->responses['zip://nsfunc'] = FixtureFetcher::zip(['ns/src/fn.php' => "<?php\nnamespace Ns;\nif (!function_exists('Ns\\\\go')) {\n    function go() {}\n}\nclass_exists('Ns\\\\Other');\n"]);
        $this->http->responses['zip://bad'] = str_repeat('x', 6 * 1048576);
        $result = $this->check(true)->check('demo/poly');
        self::assertSame(['B4'], $result['reasons']);
        $messages = implode("\n", array_column($result['risks'], 'message'));
        foreach ([
            'demo/poly declares global functions (dd; autoload.files src/functions.php)',
            'demo/nsfunc loads autoload.files (src/fn.php) with namespaced functions only (Ns\go)',
            'demo/nsfunc calls function_exists() 1 time(s)',
            'demo/nsfunc names 1 class(es) in strings (Ns\Other',
            'demo/nodist: the dist archive could not be downloaded',
            'demo/badzip: The dist archive is not a zip file.',
            'The shipped archives are 6',
        ] as $expected) {
            self::assertStringContainsString($expected, $messages);
        }
        self::assertSame('warn', array_values(array_filter($result['risks'], static fn (array $r): bool => $r['risk'] === 'B8'))[0]['level']);
        $this->http->responses['zip://bad'] = str_repeat('x', 21 * 1048576);
        self::assertSame(['B4', 'B8'], $this->check(true)->check('demo/poly')['reasons']);
    }

    public function testMissingPackagesAndTheTreeLimit(): void
    {
        $none = $this->check(false)->check('demo/none');
        self::assertSame(['write our own', ['B7'], '(no version)'], [$none['verdict'], $none['reasons'], explode(' ', CompatibilityCheck::format($none)[0])[1] . ' ' . explode(' ', CompatibilityCheck::format($none)[0])[2]]);
        self::assertStringContainsString('has no stable release on Packagist', $none['risks'][0]['message']);
        self::assertStringContainsString('that fits ^9.0', $this->check(false)->check('demo/good:^9.0')['risks'][0]['message']);
        $require = [];
        for ($n = 1; $n <= CompatibilityCheck::MAX_PACKAGES + 1; ++$n) {
            $require["demo/d$n"] = '^1.0';
            $this->http->package("demo/d$n", [['version' => '1.0.0', 'license' => ['MIT']]]);
        }
        $this->http->package('demo/huge', [['version' => '1.0.0', 'license' => ['MIT'], 'time' => '2026-01-01T00:00:00+00:00', 'require' => $require]]);
        $huge = $this->check(false)->check('demo/huge');
        self::assertCount(CompatibilityCheck::MAX_PACKAGES + 1, $huge['dependencies']);
        self::assertStringContainsString('more than ' . CompatibilityCheck::MAX_PACKAGES . ' packages', implode("\n", array_column($huge['risks'], 'message')));
        self::assertStringContainsString('the package page could not be read', implode("\n", array_column($huge['risks'], 'message')));
    }

    public function testTheExternalSearch(): void
    {
        $this->http->package('lcobucci/clock', [['version' => '3.0.0', 'license' => ['MIT'], 'time' => '2022-12-19T00:00:00+00:00', 'require' => ['php' => '~8.1.0 || ~8.2.0']]]);
        $search = new ExternalSearch(new Packagist($this->http), new Npm($this->http), $this->check(false));
        $found = $search->find('clock', 3, 2);
        self::assertSame(['symfony/clock', 'psr/clock', 'lcobucci/clock'], array_column($found['php'], 'name'));
        self::assertSame(['write our own', 'use, through a wrapper', ''], array_column($found['php'], 'verdict'));
        self::assertSame(['v6.4.48', 'MIT', '>=8.1'], [$found['php'][0]['version'], $found['php'][0]['license'], $found['php'][0]['php']]);
        self::assertSame(['clock', 'react-clock'], array_column($found['npm'], 'name'));
        $text = implode("\n", ExternalSearch::format($found));
        self::assertStringContainsString('symfony/clock v6.4.48  283,994,294 downloads, MIT, PHP >=8.1, released 2026-10-12', $text);
        self::assertStringContainsString('verdict (quick): write our own (B4); full check: ps-module external check symfony/clock', $text);
        self::assertStringContainsString("npm:\n  clock 1.0.2  1,756 downloads/month, ISC, released 2017-07-13", $text);

        $bare = (new ExternalSearch(new Packagist($this->http), new Npm($this->http), null))->find('clock', 3, 3, false);
        self::assertSame('', $bare['php'][0]['verdict']);
        self::assertSame([], $bare['npm']);
        self::assertSame(['Packagist:', '  nothing found', 'npm:', '  x   0 downloads/month, no license, released ?'], ExternalSearch::format(['php' => [], 'npm' => [['name' => 'x', 'version' => '', 'description' => '', 'license' => '', 'monthly' => 0, 'date' => '', 'url' => '']]]));
        self::assertSame(['Packagist:', '  a/b   0 downloads, none, PHP any, released ?'], ExternalSearch::format(['php' => [['name' => 'a/b', 'description' => '', 'downloads' => 0, 'version' => '', 'license' => 'none', 'php' => '', 'time' => '', 'verdict' => '', 'reasons' => []]], 'npm' => []]));
    }

    public function testTheCommands(): void
    {
        $data = str_replace('\\', '/', sys_get_temp_dir()) . '/external-data-' . bin2hex(random_bytes(4));
        mkdir($data);
        file_put_contents("$data/symbols.json.gz", gzencode((string) json_encode(FixtureFetcher::prestashop()->data())));
        $register = "$data/external.md";
        file_put_contents($register, "## 4. Register of verdicts\n\n| Date |\n|---|\n");
        try {
            [$code, $out] = $this->command(['check', 'demo/good', "--data=$data", "--record=$register", '--for=runtime-x', '--used-in=heavenx']);
            self::assertSame(0, $code);
            self::assertStringContainsString('Verdict: use, through a wrapper', $out);
            self::assertStringContainsString("Recorded the verdict in $register.", $out);
            self::assertStringContainsString('| 2026-11-07 | `demo/good` | 1.2.0 | runtime-x | use, through a wrapper |', (string) file_get_contents($register));
            [$code, $out] = $this->command(['check', 'symfony/clock', "--data=$data", '--quick', '--json', '--ps-min=9.0.0']);
            self::assertSame(1, $code);
            self::assertSame(['9.0.0'], json_decode($out, true)['prestashop']);
            [$code, $out] = $this->command(['find', 'clock', "--data=$data", '--limit=3', '--checked=1', '--no-npm']);
            self::assertSame(0, $code);
            self::assertStringContainsString('symfony/clock', $out);
            self::assertStringNotContainsString('npm:', $out);
            [$code, $out] = $this->command(['find', 'clock', "--data=$data", '--limit=3', '--checked=0', '--json']);
            self::assertSame(['symfony/clock', 'psr/clock', 'lcobucci/clock'], array_column(json_decode($out, true)['php'], 'name'));
            foreach ([[], ['find'], ['other', 'x']] as $args) {
                [$code, , $err] = $this->command($args);
                self::assertSame(2, $code);
                self::assertStringStartsWith('Usage:', $err);
            }
            [$code, , $err] = $this->command(['check', 'demo/good', "--data=$data/none"]);
            self::assertSame(1, $code);
            self::assertStringContainsString('is missing or not a PrestaShop index file', $err);
        } finally {
            foreach (glob("$data/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($data);
        }
    }

    public function testTheFetcherOfTheCommands(): void
    {
        self::assertInstanceOf(StreamFetcher::class, Cli::fetcher([], null));
        self::assertInstanceOf(CachedFetcher::class, Cli::fetcher([], '/workspace'));
        self::assertInstanceOf(CachedFetcher::class, Cli::fetcher(['check', '--cache=/tmp/x'], null));
    }

    /**
     * The check on the fake Packagist and the test PrestaShop index.
     */
    private function check(bool $deep): CompatibilityCheck
    {
        return new CompatibilityCheck(new Packagist($this->http), FixtureFetcher::prestashop(), $deep, $this->now);
    }

    /**
     * Runs a command; returns the exit code, the output and the errors.
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function command(array $args): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = Cli::run($args, $out, $err, $this->http, $this->now);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
