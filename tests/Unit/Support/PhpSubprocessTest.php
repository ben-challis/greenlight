<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class PhpSubprocessTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function runSuppliesPhp(): void
    {
        $result = PhpSubprocess::run($this->workspace->path(), [
            '-r',
            'echo PHP_BINARY;',
        ]);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe(\PHP_BINARY);
        Expect::that($result->stderr)->toBe('');
    }

    #[Test]
    public function runPreservesTheProcessOutput(): void
    {
        $result = PhpSubprocess::run($this->workspace->path(), [
            '-r',
            'fwrite(STDOUT, "stdout:exact"); fwrite(STDERR, "stderr:exact");',
        ]);

        Expect::that($result->stdout)->toBe('stdout:exact');
        Expect::that($result->stderr)->toBe('stderr:exact');
    }

    #[Test]
    public function commandPlacesPhpArgumentsBeforeTheProgram(): void
    {
        Expect::that(PhpSubprocess::command(
            ['probe.php', 'program-argument'],
            ['-d', 'precision=3'],
        ))->toBe([
            \PHP_BINARY,
            '-d',
            'precision=3',
            'probe.php',
            'program-argument',
        ]);
    }

    #[Test]
    public function callerEnvironmentValuesReachTheProcess(): void
    {
        $result = PhpSubprocess::run(
            $this->workspace->path(),
            [
                '-r',
                'echo getenv("GREENLIGHT_PHP_PROCESS_PROBE");',
            ],
            [
                'GREENLIGHT_PHP_PROCESS_PROBE' => 'caller',
            ],
        );

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe('caller');
        Expect::that($result->stderr)->toBe('');
    }
}
