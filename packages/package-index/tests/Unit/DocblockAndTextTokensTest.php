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
use PrestaShopHeaven\Build\PackageIndex\Docblock;
use PrestaShopHeaven\Build\PackageIndex\TextTokens;

/**
 * The docblock summary and deprecation, and the search terms of a text.
 */
final class DocblockAndTextTokensTest extends TestCase
{
    public function testTheSummaryIsTheFirstSentenceOfTheDescription(): void
    {
        self::assertSame('Loads an order.', Docblock::summary("/**\n * Loads an order. Then more.\n *\n * Details.\n */"));
        self::assertSame('Two lines without a stop', Docblock::summary("/**\n * Two lines\n * without a stop\n * @return int\n */"));
        self::assertSame('One line.', Docblock::summary('/** One line. */'));
        self::assertSame('', Docblock::summary("/**\n * @var int\n */"));
        self::assertSame('', Docblock::summary(''));
        $long = Docblock::summary('/** ' . str_repeat('word ', 60) . '*/');
        self::assertSame(Docblock::MAX_SUMMARY, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    public function testTheDeprecationIsTheTextOfTheTag(): void
    {
        self::assertSame('since 9.0, use X', Docblock::deprecation("/**\n * Old.\n *\n * @deprecated since 9.0, use X\n */"));
        self::assertSame('', Docblock::deprecation('/** @deprecated */'));
        self::assertNull(Docblock::deprecation('/** Fine. */'));
    }

    public function testTextBecomesLowercaseStemmedTermsWithNamesSplit(): void
    {
        self::assertSame(['order', 'status', 'rule', 'orderstatusrule'], TextTokens::of('OrderStatusRules'));
        self::assertSame(['send', 'mail', 'customer'], TextTokens::of('I want to send mails to the customers'));
        self::assertSame(['ps', 'shop', 'name', 'psshopname'], TextTokens::of('PS_SHOP_NAME'));
        self::assertSame(['html', 'parser', 'htmlparser', 'v2'], TextTokens::of('HTMLParser v2'));
        self::assertSame([], TextTokens::of('a the of'));
    }

    public function testStemRemovesSimpleEndings(): void
    {
        $cases = ['categories' => 'category', 'addresses' => 'address', 'statuses' => 'status', 'boxes' => 'box', 'matches' => 'match',
            'wishes' => 'wish', 'sending' => 'send', 'ordered' => 'order', 'rules' => 'rule', 'status' => 'status', 'class' => 'class',
            'bus' => 'bus', 'its' => 'its', 'need' => 'need'];
        foreach ($cases as $word => $stem) {
            self::assertSame($stem, TextTokens::stem($word), $word);
        }
    }
}
