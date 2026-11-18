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

namespace PrestaShopHeaven\Build\Pitfalls\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PrestaShopHeaven\Build\Pitfalls\Cli;
use PrestaShopHeaven\Build\Pitfalls\Collector;
use PrestaShopHeaven\Build\Pitfalls\Pitfall;
use PrestaShopHeaven\Build\Pitfalls\Search;

/**
 * The pitfalls index: collected from module notes, recipes and the curated list, searched by words, and the command line.
 */
final class PitfallsTest extends TestCase
{
    private string $workspace = '';

    /**
     * A workspace with one module, one recipe and a curated file.
     */
    protected function setUp(): void
    {
        $this->workspace = str_replace('\\', '/', sys_get_temp_dir()) . '/pitfalls-' . bin2hex(random_bytes(4));
        mkdir("$this->workspace/modules/heaven-foo", 0777, true);
        mkdir("$this->workspace/.claude/skills/ps-module/features/mails", 0777, true);
        file_put_contents("$this->workspace/modules/heaven-foo/CONTEXT.md", "# Context\n\n## Notes\n\n### Pitfalls and known issues\n\n- **Multistore:** the mail uses the shop of the order.\n  Otherwise the logo is wrong.\n- Cron runs in all shops.\n\n### Open points\n\n- Not a pitfall.\n");
        file_put_contents("$this->workspace/.claude/skills/ps-module/features/mails/README.md", "# Mails\n\n## Valkuilen\n- Mail templates need every language.\n\nText after.\n- Also in the section.\n");
        file_put_contents("$this->workspace/curated.json", json_encode([['text' => 'PHPCompatibility flags trim() without characters.', 'area' => 'php tooling']]));
    }

    /**
     * Removes the workspace.
     */
    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->workspace);
    }

    public function testThePitfallsAreCollectedFromWhereTheyAreWritten(): void
    {
        $pitfalls = Collector::workspace($this->workspace, "$this->workspace/curated.json");

        self::assertSame([
            ['text' => '**Multistore:** the mail uses the shop of the order. Otherwise the logo is wrong.', 'source' => 'modules/heaven-foo/CONTEXT.md', 'area' => 'heaven-foo'],
            ['text' => 'Cron runs in all shops.', 'source' => 'modules/heaven-foo/CONTEXT.md', 'area' => 'heaven-foo'],
            ['text' => 'Mail templates need every language.', 'source' => 'features/mails/README.md', 'area' => 'mails'],
            ['text' => 'Also in the section.', 'source' => 'features/mails/README.md', 'area' => 'mails'],
            ['text' => 'PHPCompatibility flags trim() without characters.', 'source' => 'pitfalls.json', 'area' => 'php tooling'],
        ], array_map(static fn (Pitfall $p): array => $p->toArray(), $pitfalls));
    }

    public function testSubHeadingsInsideAPitfallsSectionStayInIt(): void
    {
        $pitfalls = Collector::fromMarkdown("## Pitfalls\n\n### PS 9\n- One\n## Other\n- Two\n", 'x.md', 'x');

        self::assertSame(['One'], array_map(static fn (Pitfall $p): string => $p->text, $pitfalls));
        self::assertSame(['Last'], array_map(static fn (Pitfall $p): string => $p->text, Collector::fromMarkdown("## Valkuilen\n- Last", 'y.md', 'y')), 'the text may end inside a bullet');
    }

    public function testTheShippedCuratedListIsValid(): void
    {
        $curated = Collector::curated(dirname(__DIR__, 2) . '/pitfalls.json');

        self::assertNotEmpty($curated);
        self::assertSame([], Collector::curated("$this->workspace/missing.json"));
    }

    public function testABrokenCuratedListIsAnError(): void
    {
        file_put_contents("$this->workspace/bad.json", '{"text": "x"}');
        try {
            Collector::curated("$this->workspace/bad.json");
            self::fail('a list is needed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('must be a JSON list', $e->getMessage());
        }
        file_put_contents("$this->workspace/bad.json", '[{"text": "x"}]');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('entry 0 needs a text and an area');

        Collector::curated("$this->workspace/bad.json");
    }

    public function testAPitfallNeedsATextAndASource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Pitfall(' ', 'x.md', 'x');
    }

    public function testTheTitleIsTheFirstSentenceWithoutTheBoldLabel(): void
    {
        self::assertSame('Multistore: the mail uses the shop.', (new Pitfall("**Multistore:** the mail uses the shop. More\n text.", 's', 'a'))->title());
        self::assertSame(120, mb_strlen((new Pitfall(str_repeat('word ', 40), 's', 'a'))->title()));
    }

    public function testSearchRanksAreaHitsFirstAndNeedsHalfTheWords(): void
    {
        $pitfalls = Collector::workspace($this->workspace, "$this->workspace/curated.json");

        $hits = Search::find($pitfalls, 'mails language');
        self::assertSame('Mail templates need every language.', $hits[0]['pitfall']->text);
        self::assertSame(3, $hits[0]['score'], 'language in the text (1), mails in the area (2)');

        self::assertSame([], Search::find($pitfalls, 'webservice token cron'), 'one word of three is not enough');
        self::assertCount(1, Search::find($pitfalls, 'cron shops', 1));
        self::assertSame([], Search::find($pitfalls, 'a'));
        self::assertSame([], Search::find($pitfalls, 'cron', 0));
    }

    public function testWordsKeepRuleIdsAndDropShortWords(): void
    {
        self::assertSame(['ms-04', 'shop'], Search::words('MS-04 in a shop, shop!'));
    }

    public function testFormatListsTitleSourceAndText(): void
    {
        self::assertSame(['No pitfalls found.'], Search::format([]));
        self::assertSame(['- Cron runs. [heaven-foo, x.md]', '    Cron runs.'], Search::format([['score' => 1, 'pitfall' => new Pitfall('Cron runs.', 'x.md', 'heaven-foo')]]));
    }

    /**
     * Runs the command line; returns [exit code, stdout, stderr].
     *
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function cli(array $args, ?string $workspace): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = Cli::run($args, $workspace, fn (string $dir): array => Collector::workspace($dir, "$this->workspace/curated.json"), $out, $err);
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public function testTheCommandLine(): void
    {
        [$code, $out] = $this->cli(['stats'], $this->workspace);
        self::assertSame(0, $code);
        self::assertSame("Pitfalls: 5 (curated 1, modules 2, recipes 2)\n", $out);

        [$code, $out] = $this->cli(['find', 'cron shops'], $this->workspace);
        self::assertSame(0, $code);
        self::assertStringStartsWith('- Cron runs in all shops.', $out);

        [, $json] = $this->cli(['find', 'cron shops', '--json', '--limit=1'], null === null ? $this->workspace : null);
        self::assertSame('Cron runs in all shops.', json_decode($json, true)[0]['text'] ?? null);

        [$code, , $err] = $this->cli(['find'], $this->workspace);
        self::assertSame(2, $code);
        self::assertSame(Cli::USAGE . "\n", $err);

        [$code, , $err] = $this->cli(['stats'], null);
        self::assertSame(1, $code);
        self::assertStringContainsString('No PrestaHaven workspace', $err);

        [$code] = $this->cli(['stats', "--workspace=$this->workspace"], null);
        self::assertSame(0, $code);
    }

    public function testACollectFailureIsReported(): void
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($out);
        self::assertIsResource($err);
        $code = Cli::run(['stats'], $this->workspace, static function (string $dir): array {
            throw new \RuntimeException('broken curated list');
        }, $out, $err);
        rewind($err);

        self::assertSame(1, $code);
        self::assertSame("broken curated list\n", stream_get_contents($err));
    }
}
