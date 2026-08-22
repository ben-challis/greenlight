<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Subprocess;

final readonly class WorkerCaptureCleanupTest
{
    #[Test]
    public function captureFailureStillRunsAllCleanupStages(): void
    {
        $root = \dirname(__DIR__, 4);
        $process = Subprocess::start($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP_WRAP'
            require $argv[1];

            final class CaptureCleanupProbe
            {
                public static int $cleanups = 0;

                public function __construct(
                    private Greenlight\Test\Cleanup $cleanup,
                    private Greenlight\Tests\Fixture\Harness\RecordingDisposable $disposable,
                ) {}

                public function leavesNonRemovableBuffer(): void
                {
                    $this->cleanup->defer(static function (): void {
                        ++self::$cleanups;
                    });
                    ob_start(null, 0, PHP_OUTPUT_HANDLER_STDFLAGS & ~PHP_OUTPUT_HANDLER_REMOVABLE);
                }
            }

            Greenlight\Tests\Fixture\Harness\RecordingDisposable::reset();
            $id = new Greenlight\Test\TestId(CaptureCleanupProbe::class, 'leavesNonRemovableBuffer');
            $plan = new Greenlight\Discovery\ExecutionPlan([
                new Greenlight\Discovery\PlanEntry(
                    new Greenlight\Test\TestDefinition(
                        $id->class,
                        $id->method,
                        execution: new Greenlight\Test\ExecutionPolicy(timeoutSeconds: 1.0),
                    ),
                ),
            ]);
            $registry = new Greenlight\Harness\HarnessRegistry([
                new Greenlight\Harness\ServiceDefinition(
                    Greenlight\Tests\Fixture\Harness\RecordingDisposable::class,
                    Greenlight\Harness\Scope::PerTest,
                    static fn() => new Greenlight\Tests\Fixture\Harness\RecordingDisposable(),
                ),
            ]);

            new Greenlight\Execution\Worker\Worker($registry)->run(
                $plan,
                new Greenlight\Tests\Support\CollectingEventSink(),
            );

            fwrite(
                STDERR,
                sprintf(
                    '%d:%d:%s',
                    CaptureCleanupProbe::$cleanups,
                    Greenlight\Tests\Fixture\Harness\RecordingDisposable::disposals(),
                    Greenlight\Expect\ExpectationRuntime::deadline() === null ? 'clear' : 'set',
                ),
            );
            PHP_WRAP,
            $root . '/vendor/autoload.php',
        ]);

        try {
            $result = $process->wait(2.0);

            Expect::that($result->exitCode)
                ->because('capture cleanup MUST complete without terminating the worker')
                ->toBe(0);
            Expect::that($result->stderr)
                ->because('capture failure MUST run callbacks, close the test scope, and clear the temporal deadline')
                ->toBe('1:1:clear');
        } finally {
            $process->terminate();
        }
    }
}
