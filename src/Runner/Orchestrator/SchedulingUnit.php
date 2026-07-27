<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Discovery\ExecutionPlan;

/**
 * One test-class assignment and its required resources.
 *
 * @internal
 */
final readonly class SchedulingUnit
{
    /**
     * @var list<non-empty-string>
     */
    public array $resources;

    /**
     * @throws \InvalidArgumentException when the plan is empty
     */
    public function __construct(
        public ExecutionPlan $plan,
        public bool $isolated,
    ) {
        if ($plan->entries === []) {
            throw new \InvalidArgumentException('Scheduling units cannot be empty.');
        }

        $resources = [];

        foreach ($plan->entries as $entry) {
            foreach ($entry->metadata->resources as $resource) {
                $resources[$resource] = $resource;
            }
        }

        $this->resources = \array_values($resources);
    }
}
