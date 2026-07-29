<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ChangeDetector;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\KeyInput;
use Greenlight\Cli\Watch\WatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;

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
        $clock = new class implements WatchClock, Fake {
            /**
             * @var list<float>
             */
            public array $sleeps = [];

            #[\Override]
            public function now(): float
            {
                return 0.0;
            }

            #[\Override]
            public function sleep(float $seconds): void
            {
                $this->sleeps[] = $seconds;
            }
        };
        $runs = 0;

        new WatchLoop(
            $detector,
            new Debouncer(0.0),
            $keys,
            $clock,
            static function (string $text): void {},
        )->run(static function (array $priorityClasses) use (&$runs): array {
            ++$runs;

            return [];
        });

        Expect::that($clock->sleeps)
            ->because('an idle watch loop MUST sleep between polls')
            ->toBe([0.1])
            ->and($runs)
            ->because('idle polling MUST NOT start another test run')
            ->toBe(1);
    }
}
