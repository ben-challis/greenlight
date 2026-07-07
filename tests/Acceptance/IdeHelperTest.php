<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * The ide-helper command through the real CLI: a config with extension
 * matchers produces a lintable helper file, and a config without any says
 * so instead of writing one.
 */
final class IdeHelperTest
{
    #[Test]
    public function writesALintableHelperAndSkipsWhenNothingIsConfigured(): void
    {
        $root = \dirname(__DIR__, 2);
        $target = \sys_get_temp_dir() . '/greenlight-ide-helper-' . \bin2hex(\random_bytes(6)) . '.php';
        $expect = new Expect();

        try {
            [$exit, $output] = $this->run($root . '/tests/Fixture/PhpStanExtension', '--output=' . $target);
            $expect->that($exit)->toBe(0)
                ->and($output)->toContain('2 matchers');

            $helper = (string) \file_get_contents($target);
            $expect->that($helper)->toContain('@method self toHaveDigestLength(int $length)');

            \exec(\sprintf('%s -l %s 2>&1', \escapeshellarg(\PHP_BINARY), \escapeshellarg($target)), $lint, $lintExit);
            $expect->that($lintExit)->toBe(0);

            [$exit, $output] = $this->run($root . '/tests/Fixture/ListTestsConfig', '--output=' . $target . '.none');
            $expect->that($exit)->toBe(0)
                ->and($output)->toContain('No extension matchers')
                ->and(\is_file($target . '.none'))->toBeFalse();
        } finally {
            @\unlink($target);
            @\unlink($target . '.none');
        }
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
