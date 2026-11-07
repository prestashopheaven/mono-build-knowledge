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
use PrestaShopHeaven\Build\PackageIndex\PhpApiScanner;

/**
 * The tokenizer-based API scanner: declarations, members, signatures, docblocks, deprecations, imports and the odd
 * corners (anonymous classes, attributes, broken code).
 */
final class PhpApiScannerTest extends TestCase
{
    public function testItReadsClassesWithParentsConstantsAndMethods(): void
    {
        $code = <<<'PHP'
<?php
namespace Shop\Orders;

use Shop\Base\Model as BaseModel, Shop\Contracts\{Saveable, Loadable as Load};
use function strlen;
use const PHP_EOL;

/**
 * An order of the shop. It has lines.
 *
 * @deprecated since 2.0, use Purchase
 */
#[\Attribute]
final class Order extends BaseModel implements Saveable, Load, \Countable, namespace\Local
{
    use HasLines, \Shop\Traits\Stamps {
        HasLines::total insteadof Stamps;
    }

    /** The table. */
    public const TABLE = 'orders', OTHER = [1, (2)];
    protected const HIDDEN = 1;
    private const SECRET = 2;
    public int $id = 0;
    var $legacy;

    /**
     * Loads one order.
     */
    #[Pure]
    public static function load(int $id /* the id */, #[\SensitiveParameter] string $key = 'a', array $options = [],): ?self
    {
        return null;
    }

    protected function &reference ()
    {
        static $x = [];

        return $x;
    }

    private function hidden(): void
    {
    }

    abstract public function save(): bool;

    final public function count(): int
    {
        @trigger_error('Order::count() is deprecated.', E_USER_DEPRECATED);

        return 0;
    }

    public function maybe(?int $old = null): void
    {
        if ($old !== null) {
            trigger_deprecation('shop', '1.0', 'Passing $old is deprecated.');
        }
        $text = "{$old} ${old}";
        trigger_error('notice only', E_USER_NOTICE);
        $f = trigger_deprecation;
    }
}
PHP;
        $api = (new PhpApiScanner())->scan($code);
        self::assertCount(1, $api['classes']);
        $class = $api['classes'][0];
        self::assertSame('Shop\Orders\Order', $class['name']);
        self::assertSame('class', $class['kind']);
        self::assertTrue($class['final']);
        self::assertFalse($class['abstract']);
        self::assertSame('An order of the shop.', $class['summary']);
        self::assertSame('since 2.0, use Purchase', $class['deprecated']);
        self::assertSame(['Shop\Base\Model'], $class['extends']);
        self::assertSame(['Shop\Contracts\Saveable', 'Shop\Contracts\Loadable', 'Countable', 'Shop\Orders\Local'], $class['implements']);
        self::assertSame(['Shop\Orders\HasLines', 'Shop\Traits\Stamps'], $class['traits']);
        self::assertSame(['TABLE', 'OTHER', 'HIDDEN', 'SECRET'], array_column($class['constants'], 'name'));
        self::assertSame(['public', 'public', 'protected', 'private'], array_column($class['constants'], 'visibility'));
        self::assertSame('The table.', $class['constants'][0]['summary']);

        $methods = array_column($class['methods'], null, 'name');
        self::assertSame(['load', 'reference', 'hidden', 'save', 'count', 'maybe'], array_keys($methods));
        self::assertSame("(int \$id, string \$key = 'a', array \$options = []): ?self", $methods['load']['signature']);
        self::assertTrue($methods['load']['static']);
        self::assertSame('Loads one order.', $methods['load']['summary']);
        self::assertSame('protected', $methods['reference']['visibility']);
        self::assertSame('()', $methods['reference']['signature']);
        self::assertSame('private', $methods['hidden']['visibility']);
        self::assertTrue($methods['save']['abstract']);
        self::assertSame('(): bool', $methods['save']['signature']);
        self::assertTrue($methods['count']['final']);
        self::assertSame('trigger_deprecation', $methods['count']['deprecated']);
        self::assertNull($methods['maybe']['deprecated'], 'a deprecation inside an if deprecates one way of calling only');
        self::assertSame([], $api['functions']);
    }

    public function testItReadsInterfacesTraitsEnumsAndFunctions(): void
    {
        $code = <<<'PHP'
<?php
interface Clock extends \Stringable
{
    public function now(): \DateTimeImmutable;
}
abstract class Base
{
}
trait Stamps
{
    public function stamp(): void
    {
    }
}
enum Suit: string
{
    case Hearts = 'h';
    case Spades;
    const string WILD = 'w';
}
/**
 * Escapes a string.
 */
function &pSQL($string, $htmlOK = false)
{
    return $string;
}
if (!function_exists('later')) {
    /** @deprecated */
    function later(): void
    {
    }
}
function old(): void
{
    trigger_deprecation('x', '1', 'old');
}
$closure = function () {
};
$name = Base::class;
PHP;
        $api = (new PhpApiScanner())->scan($code);
        $classes = array_column($api['classes'], null, 'name');
        self::assertSame('interface', $classes['Clock']['kind']);
        self::assertSame(['Stringable'], $classes['Clock']['extends']);
        self::assertTrue($classes['Clock']['methods'][0]['abstract']);
        self::assertTrue($classes['Base']['abstract']);
        self::assertSame('trait', $classes['Stamps']['kind']);
        self::assertSame('enum', $classes['Suit']['kind']);
        self::assertSame(['Hearts', 'Spades', 'WILD'], array_column($classes['Suit']['constants'], 'name'));
        $functions = array_column($api['functions'], null, 'name');
        self::assertSame(['pSQL', 'later', 'old'], array_keys($functions));
        self::assertSame('($string, $htmlOK = false)', $functions['pSQL']['signature']);
        self::assertSame('Escapes a string.', $functions['pSQL']['summary']);
        self::assertSame('', $functions['later']['deprecated']);
        self::assertSame('trigger_deprecation', $functions['old']['deprecated']);
    }

    public function testATopLevelDeprecationDeprecatesTheClassesOfTheFile(): void
    {
        $code = <<<'PHP'
<?php
namespace Old {
    @trigger_error('This file is deprecated.', E_USER_DEPRECATED);

    class Legacy
    {
    }

    /** @deprecated use New */
    class Marked
    {
    }
}
namespace {
    class GlobalOne
    {
    }
}
PHP;
        $classes = array_column((new PhpApiScanner())->scan($code)['classes'], null, 'name');
        self::assertSame('trigger_error at the top of the file', $classes['Old\Legacy']['deprecated']);
        self::assertSame('use New', $classes['Old\Marked']['deprecated']);
        self::assertSame('trigger_error at the top of the file', $classes['GlobalOne']['deprecated']);
    }

    public function testAnonymousClassesAttributesAndPropertyHooksAreSkipped(): void
    {
        $code = <<<'PHP'
<?php
#[Attr([1, [2]])]
$a = new class(1, [2]) extends Base {
    public function inside(): void
    {
    }
};
$b = new class {
};
final readonly class Point
{
    use Simple;

    #[Attr]
    public int $x {
        get => 1;
    }

    public function __construct()
    {
    }
}
PHP;
        $classes = (new PhpApiScanner())->scan($code)['classes'];
        self::assertSame(['Point'], array_column($classes, 'name'));
        self::assertSame(['__construct'], array_column($classes[0]['methods'], 'name'));
    }

    public function testBrokenOrTruncatedCodeStillGivesWhatItCan(): void
    {
        $scanner = new PhpApiScanner();
        self::assertNotSame([], PhpApiScanner::tokens('<?php class {'));
        self::assertSame(['A'], array_column($scanner->scan('<?php class A { public function')['classes'], 'name'));
        self::assertSame(['run'], array_column($scanner->scan('<?php class A { public function run()')['classes'][0]['methods'], 'name'));
        self::assertSame(['run'], array_column($scanner->scan('<?php class A { public function run() { if (1) {')['classes'][0]['methods'], 'name'));
        self::assertSame(['B'], $scanner->scan('<?php class A { use B')['classes'][0]['traits']);
        self::assertSame([], $scanner->scan('<?php use A\B')['classes']);
        self::assertSame([], $scanner->scan('<?php class A { public function (')['classes'][0]['methods']);
        self::assertSame([], $scanner->scan('<?php class')['classes']);
        self::assertSame([], $scanner->scan('<?php $x = Foo::class; class {')['classes'], '::class in code that does not parse');
        self::assertSame(['f'], array_column($scanner->scan('<?php function f')['functions'], 'name'));
        self::assertSame('A', $scanner->scan('<?php class A { const')['classes'][0]['name']);
    }
}
