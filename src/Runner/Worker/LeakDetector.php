<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Test\TestId;

/**
 * In debug mode, verifies that PHP can collect test instances.
 *
 * watch() tracks each test instance through a weak reference. sweep() runs
 * after each test and forces a collection cycle. It identifies an instance
 * that remains after its test.
 *
 * The detector reports each leak one time.
 *
 * The environment check identifies settings that prevent correct detection.
 * Xdebug develop mode retains the stack frames of a caught
 * exception until shutdown. These frames include $this. Thus, the detector
 * reports a leak for a test that throws and catches an exception.
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
     * @param list<string>|null $xdebugModes Explicit mode snapshot. A null value reads the environment.
     *
     * @return non-empty-string|null A warning if the environment can cause incorrect leak reports
     */
    public static function environmentWarning(?array $xdebugModes = null): ?string
    {
        if (!\in_array(
            'develop',
            $xdebugModes ?? (\extension_loaded('xdebug') ? self::xdebugModes() : []),
            true,
        )) {
            return null;
        }

        return 'Warning: Xdebug develop mode keeps caught exceptions in memory. Thus, leak detection reports false positives. Rerun with XDEBUG_MODE=off to get correct results.';
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

        // Collected instances require no more checks. The detector reports a
        // leaked instance one time. Clear the watch list in both conditions.
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
