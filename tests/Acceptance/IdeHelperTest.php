<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

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

        [$exit, $output] = $this->run($root . '/tests/Fixture/PhpStanExtension', '--output=' . $target);
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('2 matchers');

        $helper = (string) \file_get_contents($target);
        Expect::that($helper)->toContain('@method self toHaveDigestLength(int $length)');

        \exec(\sprintf('%s -l %s 2>&1', \escapeshellarg(\PHP_BINARY), \escapeshellarg($target)), $lint, $lintExit);
        Expect::that($lintExit)->toBe(0);

        [$exit, $output] = $this->run($root . '/tests/Fixture/ListTestsConfig', '--output=' . $target . '.none');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('No extension matchers')
            ->and(\is_file($target . '.none'))->toBeFalse();
    }

    /**
     * @return array{int, string}
     */
    private function run(string $cwd, string ...$flags): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'ide-helper'];

        foreach ($flags as $flag) {
            $parts[] = \escapeshellarg($flag);
        }

        \exec(\sprintf('cd %s && %s 2>&1', \escapeshellarg($cwd), \implode(' ', $parts)), $output, $exit);

        return [$exit, \implode("\n", $output)];
    }
}
