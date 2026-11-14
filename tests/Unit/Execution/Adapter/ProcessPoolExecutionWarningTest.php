<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Adapter;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Execution\Adapter\ProcessPoolExecution;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Assign;
use Greenlight\Execution\RunCoordinator;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\ReactiveWorkerTransport;

use function Greenlight\expect;

final readonly class ProcessPoolExecutionWarningTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aWorkerThatExitsBeforeItsReportReturnsAWarning(): void
    {
        $resolved = ConfigurationResolver::resolve(GreenlightConfig::create()->build(), new CliOverrides());
        $transport = new ReactiveWorkerTransport(
            ReactiveWorkerTransport::connectAtStart(),
            ReactiveWorkerTransport::passingWorkers(
                static function (ReactiveWorkerTransport $transport, string $workerId, Assign $assign): bool {
                    $transport->pass($workerId, $assign, done: false);
                    $transport->disconnect($workerId);

                    return true;
                },
            ),
        );

        $result = new RunCoordinator($this->tempDirectory->path())->run(
            $resolved,
            $resolved->selection,
            [FixturePath::get('DiscoveryBasic')],
            new CollectingEventSink(),
            new ProcessPoolExecution(
                ['unused-worker-command'],
                $this->tempDirectory->path(),
                1,
                $resolved->workers,
                transport: $transport,
            ),
        );

        expect($result->summary->passed)->toBe(7);
        expect($result->warnings)->not()->toBeEmpty();
        expect($result->warnings[0] ?? '')
            ->because('a lost assignment report MUST reach the run result as a warning')
            ->toStartWith('Worker "w-1" exited after its last test and before it sent its assignment report.');
    }
}
