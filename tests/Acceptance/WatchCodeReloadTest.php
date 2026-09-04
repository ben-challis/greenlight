<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class WatchCodeReloadTest
{
    public function __construct(private TemporaryDirectory $directory, private Cleanup $cleanup) {}

    #[Test]
    #[DataSet('workerCounts')]
    public function reloadsChangedBodiesAndDiscoversAddedMethods(int $workers): void
    {
        $project = AcceptanceProject::create($this->directory, 'watch-code-reload');
        $project->writeFile('tests/ProbeTest.php', $this->source(''));
        $project->configureWithTestFiles(['tests/ProbeTest.php']);
        $process = GreenlightCli::start($project->directory, [
            'run', '--watch', '--reporter=plain', '--reporter=jsonl=events.jsonl', '--workers=' . $workers,
        ]);
        $this->cleanup->defer($process->terminate(...));
        Expect::that($process->readStdoutUntil('Waiting for changes', 20.0))->toContain('1 test, 1 passed');
        $project->writeFile('tests/ProbeTest.php', $this->source('throw new \\RuntimeException("changed body");', true));
        $output = $process->readStdoutUntil('Waiting for changes', 20.0);
        $process->write('q');
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)->toContain('2 tests, 1 passed, 1 errored')->toContain('changed body');
        if ($workers === 1) {
            Expect::that($output)->toContain('uncaptured stdout');
            Expect::that($result->stderr)->toContain('uncaptured stderr');
        }
        $report = \file_get_contents($project->path('events.jsonl'));
        Expect::that($report === false ? '' : $report)->toContain('changed body');
        Expect::that(\substr_count($report === false ? '' : $report, '"event":"run-finished"'))->toBe(2);
    }

    #[Test]
    #[SkipUnless(FunctionAvailable::class, 'pcntl_signal')]
    public function interruptionDrainsTheCurrentChildAndRunsItsCleanup(): void
    {
        $project = AcceptanceProject::create($this->directory, 'watch-interrupt');
        $project->writeFile('tests/ProbeTest.php', <<<'PHP'
            <?php
            namespace WatchInterruptProbe;
            final class ProbeTest
            {
                #[\Greenlight\Attribute\Test]
                public function waits(): void
                {
                    try {
                        usleep(500_000);
                    } finally {
                        file_put_contents(__DIR__ . '/../cleaned', 'yes');
                    }
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/ProbeTest.php']);
        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--reporter=jsonl', '--workers=1']);
        $this->cleanup->defer($process->terminate(...));
        $process->readStdoutUntil('test-started', 20.0);
        $process->signal(\SIGTERM);
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)->toBe(128 + \SIGTERM);
        Expect::that(\file_get_contents($project->path('cleaned')))->toBe('yes');
        Expect::that($result->stdout)->toContain('run-finished');
    }

    #[Test]
    public function reportsAChildExitAndClosesTheWatchCommand(): void
    {
        $project = AcceptanceProject::create($this->directory, 'watch-child-exit');
        $project->writeFile('tests/ProbeTest.php', $this->source('exit(23);'));
        $project->configureWithTestFiles(['tests/ProbeTest.php']);
        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--reporter=plain', '--workers=1']);
        $this->cleanup->defer($process->terminate(...));
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)->toContain('The PHP process did not complete the watch iteration.');
    }

    #[Test]
    public function keepsWatchingAfterASyntaxErrorIsSavedAndThenFixed(): void
    {
        $project = AcceptanceProject::create($this->directory, 'watch-syntax-repair');
        $project->writeFile('tests/ProbeTest.php', $this->source(''));
        $project->configureWithTestFiles(['tests/ProbeTest.php']);
        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--reporter=plain', '--workers=1']);
        $this->cleanup->defer($process->terminate(...));
        $process->readStdoutUntil('Waiting for changes', 20.0);
        $project->writeFile('tests/ProbeTest.php', '<?php syntax error');
        $process->readStdoutUntil('Waiting for changes', 20.0);
        $project->writeFile('tests/ProbeTest.php', $this->source(''));
        $output = $process->readStdoutUntil('Waiting for changes', 20.0);
        $process->write('q');
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)->toContain('1 test, 1 passed');
        Expect::that($result->stderr)->toContain('ParseError');
    }

    #[Test]
    #[SkipUnless(FunctionAvailable::class, 'pcntl_signal')]
    public function reporterFailureWaitsForTheChildToCleanUp(): void
    {
        $project = AcceptanceProject::create($this->directory, 'watch-reporter-failure');
        $project->writeFile('tests/ProbeTest.php', $this->source(
            'try { usleep(500_000); } finally { file_put_contents(__DIR__ . "/../cleaned", "yes"); }',
        ));
        $project->writeFile('FailingReporter.php', <<<'PHP'
            <?php
            namespace WatchReporterFailureProbe;

            final class FailingReporter implements \Greenlight\Plugin\ReporterProvider, \Greenlight\Reporting\Reporter
            {
                public function reporters(): array
                {
                    file_put_contents(__DIR__ . '/provided', "once\n", FILE_APPEND);

                    return [new \Greenlight\Reporting\ReporterDefinition('failing', static fn(): self => new self())];
                }

                public function onEvent(\Greenlight\Event\Event $event): void
                {
                    if ($event instanceof \Greenlight\Event\TestStarted) {
                        throw new \RuntimeException('reporter stopped');
                    }
                }

                public function finish(): void {}
            }
            PHP);
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php
            require_once __DIR__ . '/tests/ProbeTest.php';
            require_once __DIR__ . '/FailingReporter.php';
            return \Greenlight\Config\GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1)
                ->plugins(static fn(): \WatchReporterFailureProbe\FailingReporter => new \WatchReporterFailureProbe\FailingReporter());
            PHP);
        $process = GreenlightCli::start($project->directory, ['run', '--watch', '--reporter=failing']);
        $this->cleanup->defer($process->terminate(...));
        $result = $process->wait(10.0);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)->toContain('reporter stopped');
        Expect::that(\file_get_contents($project->path('cleaned')))->toBe('yes');
        Expect::that(\file_get_contents($project->path('provided')))->toBe("once\n");
    }

    /** @return iterable<string, array{positive-int}> */
    public static function workerCounts(): iterable
    {
        yield 'one worker' => [1];
        yield 'process pool' => [2];
    }

    private function source(string $body, bool $addedMethod = false): string
    {
        $added = $addedMethod ? <<<'PHP'
            #[\Greenlight\Attribute\Test(capture: false)]
            public function added(): void
            {
                echo 'uncaptured stdout';
                fwrite(STDERR, 'uncaptured stderr');
            }
            PHP : '';

        return '<?php namespace WatchCodeReloadProbe; final class ProbeTest {'
            . '#[\\Greenlight\\Attribute\\Test] public function original(): void {' . $body . '}'
            . $added . '}';
    }
}
