<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ChangeDetector;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\KeyInput;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\SystemWatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Cli\Watch\FakeWatchClock;

final readonly class WatchTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function rejectsANegativeQuietPeriodWithExactGuidance(): void
    {
        Expect::that(
            static fn(): Debouncer => new Debouncer(-0.1),
        )->toThrow(
            \InvalidArgumentException::class,
            message: 'Set the quiet period to zero seconds or more.',
        );
    }

    #[Test]
    public function debounceFiresOnlyAfterTheQuietPeriod(): void
    {
        $debouncer = new Debouncer(0.2);

        Expect::that($debouncer->shouldFire(10.0))->because('debounce fires only after the quiet period')->toBeFalse();

        $debouncer->noteChange(10.0);
        Expect::that($debouncer->shouldFire(10.1))->because('debounce fires only after the quiet period')->toBeFalse();

        // Multiple consecutive changes restart the quiet timer.
        $debouncer->noteChange(10.15);
        Expect::that($debouncer->shouldFire(10.3))->because('debounce fires only after the quiet period')->toBeFalse();
        Expect::that($debouncer->shouldFire(10.4))->because('debounce fires only after the quiet period')->toBeTrue();

        $debouncer->reset();
        Expect::that($debouncer->shouldFire(11.0))->because('debounce fires only after the quiet period')->toBeFalse();
    }

    #[Test]
    public function debounceFiresAtTheExactQuietPeriodBoundary(): void
    {
        $debouncer = new Debouncer(0.5);
        $debouncer->noteChange(4.0);

        Expect::that($debouncer->shouldFire(4.5))
            ->because('the quiet period includes its exact boundary')
            ->toBeTrue();
    }

    #[Test]
    public function statDetectorReportsTouchedNewAndDeletedFiles(): void
    {
        $directory = $this->tempDirectory->subdirectory('stat-detector-file-changes');
        \file_put_contents($directory . '/A.php', '<?php // a');
        $detector = new StatChangeDetector([$directory]);

        Expect::that($detector->poll())->toBe([]);

        // Both changes occur in the same second. Thus, a size change shows
        // that the fingerprint operates correctly.
        \file_put_contents($directory . '/A.php', '<?php // a changed');
        Expect::that($detector->poll())->toBe([$directory . '/A.php']);
        Expect::that($detector->poll())->toBe([]);

        \file_put_contents($directory . '/B.php', '<?php // b');
        Expect::that($detector->poll())->toBe([$directory . '/B.php']);

        \unlink($directory . '/A.php');
        Expect::that($detector->poll())->toBe([$directory . '/A.php']);
    }

    #[Test]
    public function statDetectorIgnoresMissingDirectoriesAndNonPhpFiles(): void
    {
        $root = $this->tempDirectory->subdirectory('stat-detector-file-selection');
        $directory = $this->tempDirectory->subdirectory('stat-detector-file-selection/nested');
        $ignoredFile = $directory . '/notes.txt';
        $watchedFile = $directory . '/NestedTest.php';
        \file_put_contents($ignoredFile, 'first');
        \file_put_contents($watchedFile, '<?php // first');

        $detector = new StatChangeDetector([
            $root . '/missing',
            $root,
        ]);

        Expect::that($detector->poll())
            ->because('the first poll only records PHP files from directories that exist')
            ->toBe([]);

        \file_put_contents($ignoredFile, 'second and larger');

        Expect::that($detector->poll())
            ->because('changes to non-PHP files are ignored')
            ->toBe([]);

        \file_put_contents($watchedFile, '<?php // second and larger');

        Expect::that($detector->poll())
            ->because('nested PHP files are watched')
            ->toBe([$watchedFile]);
    }

    #[Test]
    public function loopReportsThePluralForAMultiFileChange(): void
    {
        $detector = new class implements ChangeDetector, Fake {
            private int $polls = 0;

            #[\Override]
            public function poll(): array
            {
                ++$this->polls;

                return $this->polls === 1
                    ? []
                    : ['/tmp/FirstTest.php', '/tmp/SecondTest.php'];
            }
        };
        $keys = new class implements KeyInput, Fake {
            #[\Override]
            public function poll(): ?string
            {
                return null;
            }
        };
        $clock = new FakeWatchClock();
        $output = '';

        new WatchLoop(
            $detector,
            new Debouncer(0.0),
            $keys,
            $clock,
            static function (string $text) use (&$output): void {
                $output .= $text;
            },
        )->run(static fn(array $priorityClasses): array => [], maxIterations: 2);

        $ready = "\nWaiting for changes. Press Enter to rerun the selected tests. Press q to quit.\n";

        Expect::that($output)
            ->because('a multi-file watch batch MUST use the plural notification')
            ->toBe($ready . "Detected changes in 2 files.\n" . $ready);
        Expect::that($clock->sleeps)
            ->because('a zero quiet period MUST run without sleeping')
            ->toBe([]);
    }

    #[Test]
    public function loopRecordsTheBaselineBeforeTheInitialRunAndReadyOutput(): void
    {
        $events = [];
        $record = static function (string $event) use (&$events): void {
            $events[] = $event;
        };

        $detector = new readonly class ($record) implements ChangeDetector, Fake {
            /** @param \Closure(string): void $record */
            public function __construct(private \Closure $record) {}

            #[\Override]
            public function poll(): array
            {
                ($this->record)('baseline');

                return [];
            }
        };
        $keys = new readonly class ($record) implements KeyInput, Fake {
            /** @param \Closure(string): void $record */
            public function __construct(private \Closure $record) {}

            #[\Override]
            public function poll(): string
            {
                ($this->record)('key');

                return 'q';
            }
        };

        new WatchLoop(
            $detector,
            new Debouncer(0.0),
            $keys,
            new SystemWatchClock(),
            static function (string $text) use ($record): void {
                $record('ready');
            },
        )->run(static function (array $priorityClasses) use ($record): array {
            $record('run');

            return [];
        });

        Expect::that($events)->toBe(['baseline', 'run', 'ready', 'key']);
    }

    #[Test]
    public function loopStopsAfterTheCurrentRunWhenShutdownIsRequested(): void
    {
        $detector = new class implements ChangeDetector, Fake {
            public int $polls = 0;

            #[\Override]
            public function poll(): array
            {
                ++$this->polls;

                return [];
            }
        };
        $keys = new class implements KeyInput, Fake {
            #[\Override]
            public function poll(): ?string
            {
                Fail::because('Watch mode MUST not poll for keys after a shutdown request.');
            }
        };
        $shutdown = new GracefulShutdown();
        $runs = 0;

        new WatchLoop(
            $detector,
            new Debouncer(0.0),
            $keys,
            new SystemWatchClock(),
            static function (string $text): void {},
            $shutdown,
        )->run(static function (array $priorityClasses) use (&$runs, $shutdown): array {
            ++$runs;
            $shutdown->request(15);

            return [];
        });

        Expect::that($runs)
            ->because('watch mode stops after the run that receives a shutdown request')
            ->toBe(1);
        Expect::that($detector->polls)
            ->because('watch mode does not poll again after a shutdown request')
            ->toBe(1);
        Expect::that($shutdown->signal())
            ->because('watch mode keeps the signal that requested shutdown')
            ->toBe(15);
    }

    #[Test]
    public function loopDebouncesBurstsForcesOnEnterAndQuitsOnQ(): void
    {
        // Each scripted tick increases virtual time by 0.1 seconds.
        $clock = new FakeWatchClock();

        // Make two rapid changes, then no changes.
        $detector = new class implements ChangeDetector {
            public int $tick = 0;

            #[\Override]
            public function poll(): array
            {
                ++$this->tick;

                return match ($this->tick) {
                    2, 3 => ['/tmp/file.php'],
                    default => [],
                };
            }
        };

        // Send Enter after the delayed run, and then send q.
        $keys = new class implements KeyInput {
            public int $tick = 0;

            #[\Override]
            public function poll(): ?string
            {
                ++$this->tick;

                return match ($this->tick) {
                    9 => "\n",
                    11 => 'q',
                    default => null,
                };
            }
        };

        $runs = [];
        $runOnce = static function (array $priorityClasses) use (&$runs): array {
            $runs[] = $priorityClasses;

            return ['App\\BrokenTest'];
        };

        $output = '';
        new WatchLoop($detector, new Debouncer(0.2), $keys, $clock, static function (string $text) use (&$output): void {
            $output .= $text;
        })->run($runOnce, maxIterations: 10);

        // The sequence has an initial run and one delayed run for the changes.
        // The delayed run starts with classes that initially failed. Enter
        // then causes one complete run.
        Expect::that($runs)->because('loop debounces bursts forces on enter and quits on q')->toHaveCount(3);
        Expect::that($runs[0])->toBe([]);
        Expect::that($runs[1])->toBe(['App\\BrokenTest']);
        Expect::that($runs[2])->toBe([]);
        Expect::that($output)->toContain('Detected changes in 1 file.');
    }
}
