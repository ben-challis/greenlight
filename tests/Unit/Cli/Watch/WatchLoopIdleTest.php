<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ChangeDetector;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\KeyInput;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Cli\Watch\WatchLoopResult;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Cli\Watch\FakeWatchClock;

final class WatchLoopIdleTest
{
    #[Test]
    public function anIdleLoopSleepsBetweenPolls(): void
    {
        $detector = new class implements ChangeDetector, Fake {
            #[\Override]
            public function poll(): array
            {
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
        };
        $clock = new FakeWatchClock();
        $runs = 0;

        new WatchLoop(
            $detector,
            new Debouncer(0.0),
            $keys,
            $clock,
            static function (string $text): void {},
        )->run(static function (array $priorityClasses, array $changes, bool $complete, bool $mapFresh) use (&$runs): WatchLoopResult {
            ++$runs;

            return new WatchLoopResult([]);
        });

        Expect::that($clock->sleeps)
            ->because('an idle watch loop MUST sleep between polls')
            ->toBe([0.1]);
        Expect::that($runs)
            ->because('idle polling MUST NOT start another test run')
            ->toBe(1);
    }
}
