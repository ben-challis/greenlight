<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\Test\TestId;

/**
 * Debug-mode leak verification: every test instance is watched through a weak
 * reference, and a sweep after each test forces a collection cycle and names
 * any instance that survived its own test. Each leak is reported once.
 *
 * @internal
 */
final class LeakDetector
{
    /**
     * @var list<array{TestId, \WeakReference<object>}>
     */
    private array $watched = [];

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
}
