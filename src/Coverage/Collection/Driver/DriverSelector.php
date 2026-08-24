<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

use Greenlight\Coverage\CoverageError;

/**
 * The default order selects pcov before Xdebug because pcov collects line
 * coverage more quickly.
 *
 * If no driver is available, select() returns a selection with a reason for
 * the user.
 *
 * @internal
 */
final readonly class DriverSelector
{
    /**
     * @param list<class-string<CoverageDriver>> $candidates tried in order
     */
    public function __construct(private array $candidates = [PcovDriver::class, XdebugDriver::class]) {}

    /** @throws CoverageError */
    public function select(bool $branchCoverage = false): DriverSelection
    {
        foreach ($this->candidates as $candidate) {
            if ($branchCoverage && $candidate !== XdebugDriver::class) {
                continue;
            }

            if ($candidate::isAvailable()) {
                return DriverSelection::selected(
                    $candidate === XdebugDriver::class
                        ? new XdebugDriver(branchCoverage: $branchCoverage)
                        : new $candidate(),
                );
            }
        }

        if ($this->candidates === []) {
            return DriverSelection::unavailable('No coverage driver is configured.');
        }

        $names = \array_map(
            static function (string $candidate): string {
                $position = \strrpos($candidate, '\\');

                return $position === false ? $candidate : \substr($candidate, $position + 1);
            },
            $this->candidates,
        );

        return DriverSelection::unavailable(\sprintf(
            'No coverage driver is available. Greenlight tried %s. Install pcov or enable Xdebug coverage mode. '
            . 'Set xdebug.mode to "coverage", or set the XDEBUG_MODE environment variable.',
            \implode(', ', $names),
        ));
    }
}
