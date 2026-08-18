<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\ReporterSink;
use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReportingError;

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
        $event = new SuiteStarted('unit', 1.0);

        new ReporterSink($reporter)->emit($event);

        Expect::that($reporter->received)
            ->because('the reporter sink MUST preserve event identity')
            ->toBe($event);
    }

    #[Test]
    public function reporterFailuresPropagateToTheEmitter(): void
    {
        $failure = ReportingError::writeFailed();
        $reporter = new readonly class ($failure) implements Reporter, Fake {
            public function __construct(private ReportingError $failure) {}

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
            $sink->emit(new SuiteStarted('unit', 1.0));
        })
            ->because('a reporter failure MUST stop event delivery')
            ->toThrow($failure);
    }
}
