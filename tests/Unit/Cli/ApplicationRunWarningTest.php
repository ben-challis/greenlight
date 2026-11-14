<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class ApplicationRunWarningTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private EnvironmentVariables $environment,
    ) {}

    #[Test]
    #[Isolated]
    public function aLostAssignmentReportReachesTheInjectedErrorStream(): void
    {
        $this->environment->unset('GREENLIGHT_CHANNEL');
        $project = AcceptanceProject::createWithTwoPassingTests(
            $this->tempDirectory,
            'application-run-warning',
        );
        $workerBin = $this->tempDirectory->path() . '/lost-report-worker.php';
        \file_put_contents($workerBin, $this->lostReportWorker());
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();

        try {
            $exit = Application::forStreams($stdout, $stderr)->run(
                ['run', '--workers=2', '--reporter=plain', '--no-ansi'],
                $project->directory,
                $workerBin,
            );
            \rewind($stderr);
            $errors = (string) \stream_get_contents($stderr);
        } finally {
            MemoryStream::close($stdout, $stderr);
        }

        expect($exit)
            ->because('a lost assignment report after passing tests MUST not fail a run without coverage or leak detection')
            ->toBe(0);
        expect($errors)
            ->because('the warning MUST reach the error stream that the application received')
            ->toContain('Warning: Worker "w-')
            ->toContain('exited after its last test and before it sent its assignment report.');
    }

    /**
     * Returns a worker that reports each assigned test as passed and then
     * exits before it sends the assignment report.
     */
    private function lostReportWorker(): string
    {
        $autoloader = \var_export(\dirname(__DIR__, 3) . '/vendor/autoload.php', true);

        return <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Event\\TestFinished;
            use Greenlight\\Event\\TestStarted;
            use Greenlight\\Execution\\ProcessPool\\Protocol\\Messages\\Assign;
            use Greenlight\\Execution\\ProcessPool\\Protocol\\Messages\\Bootstrap;
            use Greenlight\\Execution\\ProcessPool\\Protocol\\Messages\\EventEnvelope;
            use Greenlight\\Execution\\ProcessPool\\Protocol\\Messages\\Hello;
            use Greenlight\\Execution\\ProcessPool\\Protocol\\Messages\\Ready;
            use Greenlight\\Execution\\ProcessPool\\Protocol\\SocketChannel;
            use Greenlight\\Result\\Outcome;
            use Greenlight\\Result\\TestResult;

            require {$autoloader};

            [, , \$address, \$workerId, \$token] = \$_SERVER['argv'];
            \$channel = new SocketChannel(\\stream_socket_client(\$address, \$errorCode, \$errorMessage, 10.0));
            \$channel->send(new Hello(\$workerId, \$token, \\getmypid()));

            while (true) {
                \$message = \$channel->receive(1.0);

                if (\$message instanceof Bootstrap) {
                    \$channel->send(new Ready());
                } elseif (\$message instanceof Assign) {
                    foreach (\$message->slice->entries as \$entry) {
                        \$channel->send(new EventEnvelope(new TestStarted(\$entry->id, \\microtime(true))));
                        \$channel->send(new EventEnvelope(new TestFinished(
                            new TestResult(\$entry->id, Outcome::Passed, 0.01, 0),
                            \\microtime(true),
                        )));
                    }

                    exit(0);
                } elseif (\$channel->isEof()) {
                    exit(1);
                }
            }

            PHP;
    }
}
