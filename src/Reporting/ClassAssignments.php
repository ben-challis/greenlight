<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Counts the active assignments of each test class.
 *
 * The scheduler can split one class across workers. Each assignment sends its
 * own class start and class finish events. The started() method returns true
 * only for the first start of a class. The finished() method returns true only
 * for the last finish.
 *
 * @internal
 */
final class ClassAssignments
{
    /**
     * @var array<string, positive-int>
     */
    private array $active = [];

    /**
     */
    public function started(string $class): bool
    {
        $active = $this->active[$class] ?? 0;
        $this->active[$class] = $active + 1;

        return $active === 0;
    }

    /**
     * Returns true also for a finish without a start.
     *
     */
    public function finished(string $class): bool
    {
        $active = $this->active[$class] ?? 0;

        if ($active > 1) {
            $this->active[$class] = $active - 1;

            return false;
        }

        unset($this->active[$class]);

        return true;
    }
}
