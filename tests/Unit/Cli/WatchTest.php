<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\ChangeDetector;
use Greenlight\Cli\Watch\Debouncer;
use Greenlight\Cli\Watch\KeyInput;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\WatchClock;
use Greenlight\Cli\Watch\WatchLoop;
use Greenlight\Expect\Expect;

final class WatchTest
{
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
        Expect::that($debouncer->shouldFire(10.3))->because('debounce fires only after the quiet period')->toBeFalse()
            ->and($debouncer->shouldFire(10.4))->toBeTrue();

        $debouncer->reset();
        Expect::that($debouncer->shouldFire(11.0))->because('debounce fires only after the quiet period')->toBeFalse();
    }

    #[Test]
    public function statDetectorReportsTouchedNewAndDeletedFiles(): void
    {
        $dir = \sys_get_temp_dir() . '/greenlight-watch-' . \bin2hex(\random_bytes(4));
        \mkdir($dir, 0o777, true);

        try {
            \file_put_contents($dir . '/A.php', '<?php // a');
            $detector = new StatChangeDetector([$dir]);

            Expect::that($detector->poll())->toBe([]);

            // Both changes occur in the same second. Thus, a size change shows
            // that the fingerprint operates correctly.
            \file_put_contents($dir . '/A.php', '<?php // a changed');
            Expect::that($detector->poll())->toBe([$dir . '/A.php']);
            Expect::that($detector->poll())->toBe([]);

            \file_put_contents($dir . '/B.php', '<?php // b');
            Expect::that($detector->poll())->toBe([$dir . '/B.php']);

            \unlink($dir . '/A.php');
            Expect::that($detector->poll())->toBe([$dir . '/A.php']);
        } finally {
            @\unlink($dir . '/A.php');
            @\unlink($dir . '/B.php');
            @\rmdir($dir);
        }
    }

    #[Test]
    public function loopDebouncesBurstsForcesOnEnterAndQuitsOnQ(): void
    {
        // Each scripted tick increases virtual time by 0.1 seconds.
        $clock = new class implements WatchClock {
            public float $time = 0.0;

            #[\Override]
            public function now(): float
            {
                return $this->time;
            }

            #[\Override]
            public function sleep(float $seconds): void
            {
                $this->time += $seconds;
            }
        };

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
        Expect::that($runs)->because('loop debounces bursts forces on enter and quits on q')->toHaveCount(3)
            ->and($runs[0])->toBe([])
            ->and($runs[1])->toBe(['App\\BrokenTest'])
            ->and($runs[2])->toBe([])
            ->and($output)->toContain('Detected changes in 1 file.');
    }
}
