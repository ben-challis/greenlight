<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;
use Greenlight\Tests\Support\Subprocess;

use function Greenlight\expect;

final readonly class CompletionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function printsAScriptPerShellAndRejectsUnknownShells(): void
    {
        $result = $this->run('bash');
        expect($result->exitCode)->because('prints a script per shell and rejects unknown shells')->toBe(0);
        expect($result->stdout)->toContain('_greenlight_completions')
            ->toContain('coverage:merge')
            ->toContain('coverage:diff')
            ->toContain('artifacts:prune')
            ->toContain('--detect-leaks')
            ->toContain('teamcity');

        $bashScript = $result->stdout;

        $result = $this->run('zsh');
        expect($result->exitCode)->because('prints a script per shell and rejects unknown shells')->toBe(0);
        expect($result->stdout)->toContain('compdef _greenlight greenlight')
            ->toContain('--detect-leaks')
            ->toContain('teamcity');

        $result = $this->run('fish');
        expect($result->exitCode)->because('prints a script per shell and rejects unknown shells')->toBe(0);
        expect($result->stdout)->toContain('complete -c greenlight')
            ->toContain('-l detect-leaks')
            ->toContain('teamcity');

        $result = $this->run('powershell');
        expect($result->exitCode)->because('prints a script per shell and rejects unknown shells')->toBe(64);
        expect($result->stderr)->toContain('Unknown shell');

        $result = $this->run();
        expect($result->exitCode)->because('prints a script per shell and rejects unknown shells')->toBe(64);
        expect($result->stderr)->toContain('requires a shell argument');

        $this->syntaxCheckWhenBashIsAvailable($bashScript);
    }

    /** If Bash is not available, skips only the optional syntax check. */
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
        expect($result->exitCode)->toBe(0);
    }

    private function run(string ...$arguments): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__, 2), \array_values(['completion', ...$arguments]));
    }
}
