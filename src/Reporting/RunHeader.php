<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * The reproducibility context printed as one line before test output.
 *
 * render() joins version, PHP version, config file, seed and worker count
 * with pipes, omitting the config and seed segments when absent. The worker
 * count arrives per run because only the RunStarted event knows it.
 *
 * @internal
 */
final readonly class RunHeader
{
    public function __construct(
        public string $version,
        public ?string $configFile = null,
        public ?int $seed = null,
        public string $phpVersion = \PHP_VERSION,
    ) {}

    public function render(int $workers): string
    {
        $segments = [
            'Greenlight ' . $this->version,
            'PHP ' . $this->phpVersion,
        ];

        if ($this->configFile !== null) {
            $segments[] = 'config: ' . $this->configFile;
        }

        if ($this->seed !== null) {
            $segments[] = 'seed: ' . $this->seed;
        }

        $segments[] = 'workers: ' . $workers;

        return \implode(' | ', $segments);
    }
}
