<?php

declare(strict_types=1);

namespace Greenlight\Execution;

/**
 * Describes the worker and fixture-channel capacity for one execution method.
 *
 * @internal
 */
final readonly class ExecutionTopology
{
    /**
     * @var positive-int
     */
    public int $workers;

    /**
     * @var positive-int
     */
    public int $fixtureChannels;

    public function __construct(int $workers, int $fixtureChannels)
    {
        if ($workers < 1 || $fixtureChannels < 1) {
            throw new \InvalidArgumentException('Execution topology counts MUST be positive.');
        }

        $this->workers = $workers;
        $this->fixtureChannels = $fixtureChannels;
    }
}
