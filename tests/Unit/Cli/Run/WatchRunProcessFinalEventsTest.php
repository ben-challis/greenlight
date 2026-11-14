<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Run;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Input\ParsedArguments;
use Greenlight\Cli\Output\Console;
use Greenlight\Cli\Run\WatchRunProcess;
use Greenlight\Event\Event;
use Greenlight\Event\RunStarted;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\Ticking;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

use function Greenlight\expect;

final readonly class WatchRunProcessFinalEventsTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function eventsWrittenJustBeforeTheChildExitsReachTheReporter(): void
    {
        $directory = $this->tempDirectory->subdirectory('watch-final-events');
        \file_put_contents(
            $directory . '/events.jsonl',
            EventCodec::encodeJsonLine(new RunStarted('final-events', 1, 1, 1.0)),
        );
        // The child waits until the parent is between its pipe read and its
        // status check, writes the event, and exits.
        \file_put_contents($directory . '/child.php', <<<'PHP'
            <?php
            $deadline = \microtime(true) + 10.0;
            while (!\is_file(__DIR__ . '/go') && \microtime(true) < $deadline) {
                \usleep(2_000);
            }
            $events = \fopen('php://fd/3', 'wb');
            \fwrite($events, (string) \file_get_contents(__DIR__ . '/events.jsonl'));
            \fclose($events);
            \touch(__DIR__ . '/done');
            PHP);
        $reporter = new class ($directory) implements Reporter, Ticking {
            /** @var list<Event> */
            public array $events = [];

            public function __construct(private readonly string $directory) {}

            #[\Override]
            public function onEvent(Event $event): void
            {
                $this->events[] = $event;
            }

            #[\Override]
            public function finish(): void {}

            #[\Override]
            public function tick(float $now): void
            {
                if (\is_file($this->directory . '/go')) {
                    return;
                }

                \touch($this->directory . '/go');
                $deadline = \microtime(true) + 10.0;

                while (!\is_file($this->directory . '/done') && \microtime(true) < $deadline) {
                    \usleep(2_000);
                }

                // Let the child process end before the status check.
                \usleep(200_000);
            }
        };
        $null = \fopen('/dev/null', 'wb');

        if ($null === false) {
            Fail::because('Expected to open /dev/null for the child output.');
        }

        $this->cleanup->defer(static fn(): bool => \fclose($null));
        $console = new Console($null, $null, static function (string $text): void {}, static function (string $text): void {});

        new WatchRunProcess($console, new GracefulShutdown())->run(
            $directory . '/child.php',
            new ParsedArguments(null, []),
            $directory,
            $reporter,
            [],
            null,
        );

        expect(\count($reporter->events))
            ->because('the relay MUST read the events that the child writes just before it exits')
            ->toBe(1);
    }
}
