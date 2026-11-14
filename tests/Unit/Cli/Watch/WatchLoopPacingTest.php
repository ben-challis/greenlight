<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ChangeDetector;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\KeyInput;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Doubles\Fake;
use Greenlight\Tests\Fixture\Cli\Watch\FakeWatchClock;

use function Greenlight\expect;

final class WatchLoopPacingTest
{
    #[Test]
    public function theIdleSleepGrowsWithTheScanTime(): void
    {
        $clock = new FakeWatchClock();
        $detector = new readonly class ($clock) implements ChangeDetector, Fake {
            public function __construct(private FakeWatchClock $clock) {}

            #[\Override]
            public function poll(): array
            {
                $this->clock->time += 0.5;

                return [];
            }
        };
        $keys = new class implements KeyInput, Fake {
            private int $polls = 0;

            #[\Override]
            public function poll(): ?string
            {
                return ++$this->polls === 2 ? 'q' : null;
            }

            #[\Override]
            public function discardPending(): bool
            {
                return false;
            }
        };

        new WatchLoop($detector, new Debouncer(0.0), $keys, $clock, static function (string $text): void {})
            ->run(static fn(array $priorityClasses): array => []);

        expect($clock->sleeps)
            ->because('a slow scan MUST NOT run again after only the minimum interval')
            ->toBe([2.0]);
    }

    #[Test]
    public function keysPressedDuringARunDoNotStartMoreRuns(): void
    {
        $detector = new class implements ChangeDetector, Fake {
            #[\Override]
            public function poll(): array
            {
                return [];
            }
        };
        $keys = new class implements KeyInput, Fake {
            /** @var list<non-empty-string> */
            public array $pending = [];

            public int $discards = 0;

            #[\Override]
            public function poll(): string
            {
                return \array_shift($this->pending) ?? 'q';
            }

            #[\Override]
            public function discardPending(): bool
            {
                ++$this->discards;
                $this->pending = [];

                return false;
            }
        };
        $runs = 0;

        new WatchLoop($detector, new Debouncer(0.0), $keys, new FakeWatchClock(), static function (string $text): void {})
            ->run(static function (array $priorityClasses) use (&$runs, $keys): array {
                ++$runs;
                $keys->pending = ["\n", "\n", "\n"];

                return [];
            });

        expect($runs)
            ->because('Enter keys typed during a run MUST NOT queue extra runs')
            ->toBe(1);
        expect($keys->discards)->toBe(1);
    }

    #[Test]
    public function qPressedDuringARunStopsWatchMode(): void
    {
        $detector = new class implements ChangeDetector, Fake {
            #[\Override]
            public function poll(): array
            {
                return [];
            }
        };
        $keys = new class implements KeyInput, Fake {
            #[\Override]
            public function poll(): string
            {
                return "\n";
            }

            #[\Override]
            public function discardPending(): bool
            {
                return true;
            }
        };
        $output = '';

        new WatchLoop($detector, new Debouncer(0.0), $keys, new FakeWatchClock(), static function (string $text) use (&$output): void {
            $output .= $text;
        })->run(static fn(array $priorityClasses): array => []);

        expect($output)
            ->because('q during the first run stops watch mode before it waits for changes')
            ->toBe('');
    }
}
