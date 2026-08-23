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
    public function runSuppliesPhpAndTheRequiredEnvironment(): void
    {
        $result = PhpSubprocess::run($this->workspace->path(), [
            '-r',
            <<<'PHP_WRAP'
            echo json_encode([
                PHP_BINARY,
                getenv('DD_TRACE_ENABLED'),
                getenv('DD_TRACE_CLI_ENABLED'),
                getenv('DD_TRACE_STARTUP_LOGS'),
                getenv('DD_INSTRUMENTATION_TELEMETRY_ENABLED'),
                extension_loaded('ddtrace') ? ini_get('ddtrace.disable') : 'not-loaded',
            ], JSON_THROW_ON_ERROR);
            PHP_WRAP,
        ]);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe(\json_encode([
            \PHP_BINARY,
            '0',
            '0',
            '0',
            '0',
            \extension_loaded('ddtrace') ? '1' : 'not-loaded',
        ], \JSON_THROW_ON_ERROR));
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
            '-d',
            'ddtrace.disable=1',
            'probe.php',
            'program-argument',
        ]);
    }

    #[Test]
    public function callerValuesDoNotOverrideTheRequiredEnvironment(): void
    {
        $result = PhpSubprocess::run(
            $this->workspace->path(),
            [
                '-r',
                'echo getenv("GREENLIGHT_PHP_PROCESS_PROBE") . ":" . getenv("DD_TRACE_ENABLED");',
            ],
            [
                'GREENLIGHT_PHP_PROCESS_PROBE' => 'caller',
                'DD_TRACE_ENABLED' => '1',
            ],
        );

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->stdout)->toBe('caller:0');
        Expect::that($result->stderr)->toBe('');
    }
}
