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

namespace PrestaShopHeaven\Build\PackageIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Build\PackageIndex\Catalog;
use PrestaShopHeaven\Build\PackageIndex\Cli;
use PrestaShopHeaven\Build\PackageIndex\PackageIndex;
use PrestaShopHeaven\Build\PackageIndex\Search;
use PrestaShopHeaven\Build\PackageIndex\Workspace;

/**
 * The generator, the search, the catalog and the commands, on a fake workspace.
 */
final class PackageIndexTest extends TestCase
{
    private FakeWorkspace $workspace;

    protected function setUp(): void
    {
        $this->workspace = new FakeWorkspace();
    }

    protected function tearDown(): void
    {
        $this->workspace->remove();
    }

    public function testTheIndexHoldsEveryPackageWithItsPublicApi(): void
    {
        $index = PackageIndex::workspace($this->workspace->dir . '/libraries');
        self::assertSame(PackageIndex::FORMAT, $index['format']);
        self::assertSame(['prestashopheaven/mono-build-demo', 'prestashopheaven/mono-runtime-demo'], array_column($index['monorepos'], 'name'));
        $runtime = $index['monorepos'][1];
        self::assertSame(['mono-runtime-demo', '1.4.2', 'runtime'], [$runtime['dir'], $runtime['version'], $runtime['kind']]);
        $clock = $runtime['packages'][0];
        self::assertSame('prestashopheaven/runtime-clock', $clock['name']);
        self::assertSame(['packages/clock', 'runtime', '1.4.2', '>=8.1', '8.1.0 - 9.x'], [$clock['dir'], $clock['kind'], $clock['version'], $clock['php'], $clock['prestashop']]);
        self::assertSame(['clock', 'time', 'timezone'], $clock['keywords']);
        $class = $clock['classes'][0];
        self::assertSame(['PrestaShopHeaven\Runtime\Clock\ShopTime', 'class', 'src/ShopTime.php', 'The local time of a shop.'], [$class['name'], $class['kind'], $class['file'], $class['summary']]);
        self::assertSame(['FORMAT'], $class['constants']);
        self::assertSame([['name' => 'localNow', 'signature' => '(int $shopId): \DateTimeImmutable', 'static' => false, 'summary' => 'The current time in the time zone of the shop.', 'deprecated' => null]], $class['methods']);
        self::assertSame('PrestaShopHeaven\Runtime\Clock\format_shop_time', $clock['functions'][0]['name']);
        self::assertSame([], $runtime['packages'][1]['classes'], 'a package without src/ has no API');
        self::assertSame('noname', $index['monorepos'][0]['packages'][0]['name']);
    }

    public function testGenerateWritesOneFilePerMonorepoAndTheCombinedIndex(): void
    {
        $files = PackageIndex::generate($this->workspace->dir);
        $out = $this->workspace->dir . '/.cache/index';
        self::assertSame(["$out/mono-build-demo.json", "$out/mono-runtime-demo.json", "$out/packages.json"], $files);
        self::assertCount(2, PackageIndex::load("$out/packages.json")['monorepos']);
        self::assertSame('prestashopheaven/mono-build-demo', PackageIndex::load("$out/mono-build-demo.json")['monorepos'][0]['name']);
        $before = (string) file_get_contents("$out/packages.json");
        PackageIndex::generate($this->workspace->dir, $out);
        self::assertSame($before, file_get_contents("$out/packages.json"), 'the same packages give the same file');
    }

    public function testLoadAndMonorepoRefuseFilesThatAreNoIndex(): void
    {
        $this->workspace->json('bad.json', ['format' => 99]);
        try {
            PackageIndex::load($this->workspace->dir . '/bad.json');
            self::fail('no exception');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('is not a package index', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        PackageIndex::monorepo($this->workspace->dir . '/libraries/mono-other');
    }

    public function testAMissingFileIsAnError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is missing or not valid JSON');
        PackageIndex::load($this->workspace->dir . '/none.json');
    }

    public function testSearchRanksPackagesClassesMethodsAndFunctions(): void
    {
        $index = PackageIndex::workspace($this->workspace->dir . '/libraries');
        $hits = Search::find($index, 'local time of the shop', 20);
        self::assertSame('prestashopheaven/runtime-clock', $hits[0]['package']);
        self::assertSame('composer require prestashopheaven/runtime-clock:^1.4', $hits[0]['require']);
        self::assertContains('PrestaShopHeaven\Runtime\Clock\ShopTime', array_column($hits, 'class'));
        self::assertContains('localNow(int $shopId): \DateTimeImmutable', array_column($hits, 'method'));
        self::assertContains('PrestaShopHeaven\Runtime\Clock\format_shop_time(int $time): string', array_column($hits, 'method'));
        self::assertGreaterThan(0, Search::find($index, 'timezo')[0]['score'], 'a term that starts a word counts');
        $lint = Search::find($index, 'lint');
        self::assertSame('composer require --dev prestashopheaven/build-lint', $lint[0]['require']);
        self::assertSame('Sends the module mails.', Search::find($index, 'mail')[0]['description'], 'without use-when the description');
        self::assertSame([], Search::find($index, 'the of'));
        self::assertCount(1, Search::find($index, 'time', 0));
    }

    public function testSearchHitsFormatAsLines(): void
    {
        $index = PackageIndex::workspace($this->workspace->dir . '/libraries');
        $text = implode("\n", Search::format(Search::find($index, 'shop time format', 20)));
        self::assertStringContainsString('package   prestashopheaven/runtime-clock', $text);
        self::assertStringContainsString('class     PrestaShopHeaven\Runtime\Clock\ShopTime', $text);
        self::assertStringContainsString('method    PrestaShopHeaven\Runtime\Clock\ShopTime::localNow', $text);
        self::assertStringContainsString('function  PrestaShopHeaven\Runtime\Clock\format_shop_time', $text);
        self::assertStringContainsString('prestashopheaven/runtime-clock 1.4.2 (runtime): composer require', $text);
        self::assertStringStartsWith('No package matches.', Search::format([])[0]);
    }

    public function testTheCatalogHasOneLinePerPackage(): void
    {
        self::assertSame([
            '- `prestashopheaven/build-lint`  (build, mono-build-demo): A module checks its code /style/.',
            '- `prestashopheaven/runtime-clock` 1.4.2 (runtime, mono-runtime-demo): Code needs the current time of a shop.',
            '- `prestashopheaven/runtime-mail` 1.4.2 (runtime, mono-runtime-demo): ',
        ], Catalog::workspaceLines($this->workspace->dir));
        self::assertSame('- `a/b` 1.0.0 (?, mono-x): desc', Catalog::line(['name' => 'a/b', 'description' => 'desc'], '1.0.0', 'mono-x'));
        self::assertSame([], Catalog::workspaceLines($this->workspace->dir . '/modules'));
    }

    public function testTheWorkspaceIsFoundUpwardsAndOptionsAreParsed(): void
    {
        self::assertSame($this->workspace->dir, Workspace::find([$this->workspace->dir . '/nowhere', $this->workspace->dir . '/libraries/mono-runtime-demo/packages/clock']));
        self::assertNull(Workspace::find([sys_get_temp_dir() . '/no-such-folder-' . bin2hex(random_bytes(3))]));
        self::assertSame([['limit' => '5', 'json' => '1'], ['find', 'clock']], Workspace::options(['find', '--limit=5', 'clock', '--json']));
    }

    public function testTheCommands(): void
    {
        $dir = $this->workspace->dir;
        [$code, $out] = $this->command(['generate'], $dir);
        self::assertSame(0, $code);
        self::assertStringContainsString("Wrote $dir/.cache/index/packages.json", $out);

        [$code, $out] = $this->command(['monorepo', "$dir/libraries/mono-runtime-demo", "--out=$dir/one.json"], null);
        self::assertSame([0, "Wrote $dir/one.json\n"], [$code, $out]);
        [$code, $out] = $this->command(['find', 'clock', "--index=$dir/one.json", '--json'], null);
        self::assertSame(0, $code);
        self::assertSame('prestashopheaven/runtime-clock', json_decode($out, true)[0]['package']);
        [$code, $out] = $this->command(['find', 'clock', '--limit=1'], null, ["--workspace=$dir"]);
        self::assertSame(0, $code);
        self::assertStringContainsString('prestashopheaven/runtime-clock', $out);
        [$code, $out] = $this->command(['catalog'], $dir);
        self::assertSame(0, $code);
        self::assertStringContainsString('runtime-mail', $out);
        [$code, $out] = $this->command(['catalog'], "$dir/modules");
        self::assertSame([0, ''], [$code, $out]);
    }

    public function testWrongUsageAndFailures(): void
    {
        foreach ([[], ['monorepo'], ['find'], ['nonsense']] as $args) {
            [$code, , $err] = $this->command($args, $this->workspace->dir);
            self::assertSame(2, $code);
            self::assertStringStartsWith('Usage:', $err);
        }
        [$code, , $err] = $this->command(['catalog'], null);
        self::assertSame(1, $code);
        self::assertStringContainsString('No PrestaHaven workspace', $err);
    }

    /**
     * Runs a command; returns the exit code, the output and the errors.
     *
     * @param list<string> $args
     * @param list<string> $extra
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function command(array $args, ?string $workspace, array $extra = []): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = Cli::run(array_merge($args, $extra), $out, $err, $workspace);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
