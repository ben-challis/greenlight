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
    public function debounceFiresOnlyAfterTheQuietPeriod(): void
    {
        $debouncer = new Debouncer(0.2);

        Expect::that($debouncer->shouldFire(10.0))->toBeFalse();

        $debouncer->noteChange(10.0);
        Expect::that($debouncer->shouldFire(10.1))->toBeFalse();

        // A burst restarts the quiet timer.
        $debouncer->noteChange(10.15);
        Expect::that($debouncer->shouldFire(10.3))->toBeFalse()
            ->and($debouncer->shouldFire(10.4))->toBeTrue();

        $debouncer->reset();
        Expect::that($debouncer->shouldFire(11.0))->toBeFalse();
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

            // Same second, so a size change proves the fingerprint works.
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
        // Scripted world: each tick advances virtual time by 0.1s.
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

        // Two rapid changes (a burst), then quiet, then nothing.
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

        // Enter after the debounced run, then q.
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

        // Initial run, one debounced run for the burst (with failed-first
        // classes from the initial run), and one forced full run from Enter.
        Expect::that($runs)->toHaveCount(3)
            ->and($runs[0])->toBe([])
            ->and($runs[1])->toBe(['App\\BrokenTest'])
            ->and($runs[2])->toBe([])
            ->and($output)->toContain('Change detected in 1 file(s).');
    }
}
