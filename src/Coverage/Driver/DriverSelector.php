<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

/**
 * Picks the first available coverage driver from an ordered candidate list.
 * The default order prefers pcov over Xdebug because pcov collects line
 * coverage dramatically faster. When nothing is available, selection carries
 * a reason string suitable for direct display to the user.
 *
 * @internal
 */
final readonly class DriverSelector
{
    /**
     * @param list<class-string<CoverageDriver>> $candidates tried in order
     */
    public function __construct(
        private array $candidates = [PcovDriver::class, XdebugDriver::class],
    ) {}

    public function select(): DriverSelection
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate::isAvailable()) {
                return DriverSelection::selected(new $candidate());
            }
        }

        if ($this->candidates === []) {
            return DriverSelection::unavailable('No coverage driver is available: no drivers are configured.');
        }

        $names = \array_map(
            static function (string $candidate): string {
                $position = \strrpos($candidate, '\\');

                return $position === false ? $candidate : \substr($candidate, $position + 1);
            },
            $this->candidates,
        );

        return DriverSelection::unavailable(\sprintf(
            'No coverage driver is available: tried %s. Install pcov, or enable xdebug with "coverage" in xdebug.mode or the XDEBUG_MODE environment variable.',
            \implode(', ', $names),
        ));
    }
}
