<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

/**
 * The completion command through the real CLI: each supported shell gets a
 * script on stdout with exit 0, a missing or unknown shell is a usage
 * error, and the bash script passes bash -n when bash is installed.
 */
final readonly class CompletionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function printsAScriptPerShellAndRejectsUnknownShells(): void
    {
        $result = $this->run('bash');
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->stdout)->toContain('_greenlight_completions')
            ->and($result->stdout)->toContain('coverage:diff')
            ->and($result->stdout)->toContain('--detect-leaks')
            ->and($result->stdout)->toContain('teamcity');

        $bashScript = $result->stdout;

        $result = $this->run('zsh');
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->stdout)->toContain('compdef _greenlight greenlight')
            ->and($result->stdout)->toContain('--detect-leaks')
            ->and($result->stdout)->toContain('teamcity');

        $result = $this->run('fish');
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->stdout)->toContain('complete -c greenlight')
            ->and($result->stdout)->toContain('-l detect-leaks')
            ->and($result->stdout)->toContain('teamcity');

        $result = $this->run('powershell');
        Expect::that($result->exitCode)->toBe(64)
            ->and($result->stderr)->toContain('Unknown shell');

        $result = $this->run();
        Expect::that($result->exitCode)->toBe(64)
            ->and($result->stderr)->toContain('requires a shell argument');

        $this->syntaxCheckWhenBashIsAvailable($bashScript);
    }

    /**
     * Pipes the rendered bash script through bash -n. Skipped silently when
     * bash is not installed; the rest of the test has already run by then.
     */
    private function syntaxCheckWhenBashIsAvailable(string $script): void
    {
        $path = \getenv('PATH');
        $bash = null;

        foreach (\explode(\PATH_SEPARATOR, \is_string($path) ? $path : '') as $directory) {
            $candidate = $directory . \DIRECTORY_SEPARATOR . 'bash';

            if (\is_file($candidate) && \is_executable($candidate)) {
                $bash = $candidate;

                break;
            }
        }

        if ($bash === null) {
            return;
        }

        $file = $this->tempDirectory->path() . '/completion.bash';
        \file_put_contents($file, $script . "\n");
        $result = Subprocess::run(\dirname(__DIR__, 2), [$bash, '-n', $file]);
        Expect::that($result->exitCode)->toBe(0);
    }

    private function run(string ...$arguments): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__, 2), \array_values(['completion', ...$arguments]));
    }
}
