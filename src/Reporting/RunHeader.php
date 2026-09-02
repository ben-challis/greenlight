<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/** @internal */
final readonly class RunHeader
{
    public function __construct(
        public string $version,
        public ?string $configFile = null,
        public ?int $seed = null,
        public string $phpVersion = \PHP_VERSION,
    ) {}

    public function render(int $workers, Style $style): string
    {
        $segments = ['PHP ' . $this->phpVersion];

        $segments[] = $this->configFile === null
            ? $style->warn('configuration: (none)')
            : 'configuration: ' . $this->configFile;

        $segments[] = 'workers: ' . $workers;

        if ($this->seed !== null) {
            $segments[] = $style->dim('seed: ' . $this->seed);
        }

        return $style->ok('Greenlight') . ' ' . $this->version . "\n" . \implode(' | ', $segments);
    }
}
