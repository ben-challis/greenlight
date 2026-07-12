<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Test\TestId;

/**
 * Verifies in debug mode that test instances do not leak.
 *
 * watch() tracks every test instance through a weak reference. sweep() runs
 * after each test, forces a collection cycle, and names any instance that
 * survived its own test.
 *
 * Each leak is reported once.
 *
 * environmentWarning() names environments the detector cannot see through:
 * xdebug develop mode keeps a caught exception's stack frames, including
 * $this of every frame, alive until shutdown, so any test that throws and
 * catches is reported as a leak.
 *
 * @internal
 */
final class LeakDetector
{
    /**
     * @var list<array{TestId, \WeakReference<object>}>
     */
    private array $watched = [];

    /**
     * @return non-empty-string|null a warning when the environment makes leak reports untrustworthy
     */
    public static function environmentWarning(): ?string
    {
        if (!\extension_loaded('xdebug') || !\in_array('develop', self::xdebugModes(), true)) {
            return null;
        }

        return 'Warning: xdebug develop mode keeps caught exceptions alive, so leak detection will report false positives. Rerun with XDEBUG_MODE=off for a trustworthy signal.';
    }

    public function watch(TestId $id, object $instance): void
    {
        $this->watched[] = [$id, \WeakReference::create($instance)];
    }

    /**
     * @return list<TestId> tests whose instances are still alive
     */
    public function sweep(): array
    {
        \gc_collect_cycles();

        $leaks = [];

        foreach ($this->watched as [$id, $reference]) {
            if ($reference->get() !== null) {
                $leaks[] = $id;
            }
        }

        // Collected instances need no further watching, and leaked instances
        // are reported once; either way the watch list resets.
        $this->watched = [];

        return $leaks;
    }

    /**
     * @return list<string>
     */
    private static function xdebugModes(): array
    {
        if (\function_exists('xdebug_info')) {
            $modes = \xdebug_info('mode');

            if (\is_array($modes)) {
                $names = [];

                foreach ($modes as $mode) {
                    if (\is_string($mode)) {
                        $names[] = $mode;
                    }
                }

                return $names;
            }
        }

        $ini = \ini_get('xdebug.mode');

        if (!\is_string($ini) || $ini === '') {
            return [];
        }

        return \array_map(\trim(...), \explode(',', $ini));
    }
}
