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

final class WatchLoopKeyDiscardTest
{
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
            }, maxIterations: 3);

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
        })->run(static fn(array $priorityClasses): array => [], maxIterations: 3);

        expect($output)
            ->because('q during the first run stops watch mode before it waits for changes')
            ->toBe('');
    }
}
