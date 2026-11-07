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

/**
 * A throw-away workspace in the temp folder: libraries/ with two monorepos (mono-runtime-demo with a clock and a mail
 * package, mono-build-demo with one build package), a folder that is no monorepo and an empty modules/.
 */
final class FakeWorkspace
{
    /** The workspace folder. */
    public readonly string $dir;

    /**
     * Creates the workspace.
     */
    public function __construct()
    {
        $this->dir = str_replace('\\', '/', sys_get_temp_dir()) . '/pkgindex-' . bin2hex(random_bytes(5));
        $this->json('libraries/mono-runtime-demo/composer.json', ['name' => 'prestashopheaven/mono-runtime-demo', 'version' => '1.4.2', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'runtime']]]]);
        $this->json('libraries/mono-runtime-demo/packages/clock/composer.json', [
            'name' => 'prestashopheaven/runtime-clock',
            'description' => 'The clock of a module.',
            'require' => ['php' => '>=8.1'],
            'extra' => ['prestashopheaven' => ['kind' => 'runtime', 'use-when' => 'Code needs the current time of a shop.', 'keywords' => ['clock', 'time', 'timezone'], 'prestashop' => '8.1.0 - 9.x']],
        ]);
        $this->file('libraries/mono-runtime-demo/packages/clock/src/ShopTime.php', <<<'PHP'
<?php
namespace PrestaShopHeaven\Runtime\Clock;

/**
 * The local time of a shop.
 */
final class ShopTime
{
    public const FORMAT = 'Y-m-d';
    private const SECRET = 1;

    /**
     * The current time in the time zone of the shop.
     */
    public function localNow(int $shopId): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    private function zone(): string
    {
        return 'UTC';
    }
}

/**
 * Formats a time stamp for the shop.
 */
function format_shop_time(int $time): string
{
    return '';
}
PHP);
        $this->json('libraries/mono-runtime-demo/packages/mail/composer.json', [
            'name' => 'prestashopheaven/runtime-mail',
            'description' => 'Sends the module mails.',
            'extra' => ['prestashopheaven' => ['kind' => 'runtime', 'use-when' => '', 'keywords' => ['mail']]],
        ]);
        $this->json('libraries/mono-build-demo/composer.json', ['name' => 'prestashopheaven/mono-build-demo', 'version' => '', 'extra' => ['prestashopheaven' => ['monorepo' => ['kind' => 'build']]]]);
        $this->json('libraries/mono-build-demo/packages/lint/composer.json', [
            'name' => 'prestashopheaven/build-lint',
            'description' => 'Lints the code.',
            'extra' => ['prestashopheaven' => ['kind' => 'build', 'use-when' => "A module checks\n its code |style|.", 'keywords' => ['lint']]],
        ]);
        $this->json('libraries/mono-build-demo/packages/noname/composer.json', ['description' => 'no name']);
        $this->json('libraries/mono-other/composer.json', ['name' => 'someone/else']);
        $this->file('modules/.keep', '');
    }

    /**
     * Writes a file below the workspace.
     */
    public function file(string $path, string $content): void
    {
        if (!is_dir(dirname("{$this->dir}/$path"))) {
            mkdir(dirname("{$this->dir}/$path"), 0777, true);
        }
        file_put_contents("{$this->dir}/$path", $content);
    }

    /**
     * Writes a JSON file below the workspace.
     *
     * @param array<string, mixed> $data
     */
    public function json(string $path, array $data): void
    {
        $this->file($path, (string) json_encode($data));
    }

    /**
     * Removes the workspace.
     */
    public function remove(?string $dir = null): void
    {
        $dir ??= $this->dir;
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir("$dir/$entry") ? $this->remove("$dir/$entry") : unlink("$dir/$entry");
            }
        }
        rmdir($dir);
    }
}
