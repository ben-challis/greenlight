<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\Subprocess;

/**
 * The ide-helper command through the real CLI: a config with extension
 * matchers produces a lintable helper file, and a config without any says
 * so instead of writing one.
 */
final readonly class IdeHelperTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function writesALintableHelperAndSkipsWhenNothingIsConfigured(): void
    {
        $root = \dirname(__DIR__, 2);
        $target = $this->tempDirectory->path() . '/ide-helper.php';

        $result = GreenlightCli::run($root . '/tests/Fixture/PhpStanExtension', ['ide-helper', '--output=' . $target]);
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('2 matchers');

        $helper = (string) \file_get_contents($target);
        Expect::that($helper)->toContain('@method self toHaveDigestLength(int $length)');

        $lint = Subprocess::run($root, [\PHP_BINARY, '-l', $target]);
        Expect::that($lint->exitCode)->toBe(0);

        $result = GreenlightCli::run($root . '/tests/Fixture/ListTestsConfig', ['ide-helper', '--output=' . $target . '.none']);
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('No extension matchers')
            ->and(\is_file($target . '.none'))->toBeFalse();
    }
}
