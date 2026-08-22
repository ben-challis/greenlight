<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Reporting\ReporterSink;
use Greenlight\Doubles\Fake;
use Greenlight\Event\Event;
use Greenlight\Event\RunStarted;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReportGenerationFailed;

final class ReporterSinkTest
{
    #[Test]
    public function eventsReachTheReporterWithoutReplacement(): void
    {
        $reporter = new class implements Reporter, Fake {
            public ?Event $received = null;

            #[\Override]
            public function onEvent(Event $event): void
            {
                $this->received = $event;
            }

            #[\Override]
            public function finish(): void {}
        };
        $event = new RunStarted('run-1', 1, 1, 1.0);

        new ReporterSink($reporter)->emit($event);

        Expect::that($reporter->received)
            ->because('the reporter sink MUST preserve event identity')
            ->toBe($event);
    }

    #[Test]
    public function reporterFailuresPropagateToTheEmitter(): void
    {
        $failure = ReportGenerationFailed::writeFailed();
        $reporter = new readonly class ($failure) implements Reporter, Fake {
            public function __construct(private ReportGenerationFailed $failure) {}

            #[\Override]
            public function onEvent(Event $event): never
            {
                throw $this->failure;
            }

            #[\Override]
            public function finish(): void {}
        };
        $sink = new ReporterSink($reporter);

        Expect::that(static function () use ($sink): void {
            $sink->emit(new RunStarted('run-1', 1, 1, 1.0));
        })
            ->because('a reporter failure MUST stop event delivery')
            ->toThrow($failure);
    }
}
