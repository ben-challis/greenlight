<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * The completion command through the real CLI: each supported shell gets a
 * script on stdout with exit 0, a missing or unknown shell is a usage
 * error, and the bash script passes bash -n when bash is installed.
 */
final class CompletionTest
{
    #[Test]
    public function printsAScriptPerShellAndRejectsUnknownShells(): void
    {
        [$exit, $output] = $this->run('bash');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('_greenlight_completions')
            ->and($output)->toContain('coverage:diff')
            ->and($output)->toContain('--detect-leaks')
            ->and($output)->toContain('teamcity');

        $bashScript = $output;

        [$exit, $output] = $this->run('zsh');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('compdef _greenlight greenlight')
            ->and($output)->toContain('--detect-leaks')
            ->and($output)->toContain('teamcity');

        [$exit, $output] = $this->run('fish');
        Expect::that($exit)->toBe(0)
            ->and($output)->toContain('complete -c greenlight')
            ->and($output)->toContain('-l detect-leaks')
            ->and($output)->toContain('teamcity');

        [$exit, , $stderr] = $this->run('powershell');
        Expect::that($exit)->toBe(64)
            ->and($stderr)->toContain('Unknown shell');

        [$exit, , $stderr] = $this->run();
        Expect::that($exit)->toBe(64)
            ->and($stderr)->toContain('requires a shell argument');

        $this->syntaxCheckWhenBashIsAvailable($bashScript);
    }

    /**
     * Pipes the rendered bash script through bash -n. Skipped silently when
     * bash is not installed; the rest of the test has already run by then.
     */
    private function syntaxCheckWhenBashIsAvailable(string $script): void
    {
        \exec('command -v bash 2>/dev/null', $paths, $missing);

        if ($missing !== 0) {
            return;
        }

        $file = \sys_get_temp_dir() . '/greenlight-completion-' . \bin2hex(\random_bytes(6)) . '.bash';

        try {
            \file_put_contents($file, $script . "\n");
            \exec(\sprintf('bash -n %s 2>&1', \escapeshellarg($file)), $lint, $lintExit);
            Expect::that($lintExit)->toBe(0);
        } finally {
            @\unlink($file);
        }
    }

    /**
     * Runs the completion command and captures stdout and stderr separately,
     * so stray stderr lines cannot leak into the script that bash -n checks.
     *
     * @return array{int, string, string}
     */
    private function run(string ...$arguments): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'completion'];

        foreach ($arguments as $argument) {
            $parts[] = \escapeshellarg($argument);
        }

        $errFile = \sys_get_temp_dir() . '/greenlight-completion-err-' . \bin2hex(\random_bytes(6)) . '.txt';

        try {
            \exec(\implode(' ', $parts) . ' 2>' . \escapeshellarg($errFile), $output, $exit);
            $stderr = (string) @\file_get_contents($errFile);
        } finally {
            @\unlink($errFile);
        }

        return [$exit, \implode("\n", $output), $stderr];
    }
}
