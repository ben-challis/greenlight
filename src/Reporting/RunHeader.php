<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * The reproducibility context printed before test output.
 *
 * render() produces two lines: the product name and version, then the PHP
 * version, config file, worker count and seed joined with pipes. The seed is
 * omitted when absent; the worker count arrives per run because only the
 * RunStarted event knows it.
 *
 * With an ANSI-capable Style the name renders green and the seed dim. Two
 * states render yellow: a run without a config file shows "config: (none)",
 * and workerFallback marks a parallel run that degraded to the in-process
 * runner. A colourless Style leaves the same text undecorated.
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
        public bool $workerFallback = false,
    ) {}

    public function render(int $workers, Style $style): string
    {
        $segments = ['PHP ' . $this->phpVersion];

        $segments[] = $this->configFile === null
            ? $style->skip('config: (none)')
            : 'config: ' . $this->configFile;

        $workersSegment = 'workers: ' . $workers;
        $segments[] = $this->workerFallback ? $style->skip($workersSegment) : $workersSegment;

        if ($this->seed !== null) {
            $segments[] = $style->dim('seed: ' . $this->seed);
        }

        return $style->pass('Greenlight') . ' ' . $this->version . "\n" . \implode(' | ', $segments);
    }
}
