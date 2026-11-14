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
}
