<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Coverage\PathFilter;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\Adder;

final class XdebugDriverTest
{
    #[Test]
    public function availabilityMatchesTheActiveModesAndMissingCoverageModeIsActionable(): void
    {
        $available = \extension_loaded('xdebug')
            && \in_array('coverage', $this->activeXdebugModes(), true);

        Expect::that(XdebugDriver::isAvailable())
            ->because('Xdebug availability matches the active extension modes')
            ->toBe($available);

        if ($available) {
            Expect::that(new XdebugDriver())
                ->because('an active Xdebug coverage mode permits driver construction')
                ->toBeInstanceOf(XdebugDriver::class);

            return;
        }

        Expect::that(static fn(): XdebugDriver => new XdebugDriver())
            ->because('an inactive Xdebug coverage mode gives exact configuration guidance')
            ->toThrow(
                CoverageError::class,
                message: 'Coverage driver "xdebug" is not available. Enable the Xdebug extension. '
                . 'Add "coverage" to xdebug.mode or the XDEBUG_MODE environment variable.',
            );
    }

    #[Test]
    public function reportsInvalidCollectionStateExactly(): void
    {
        $driver = new \ReflectionClass(XdebugDriver::class)->newInstanceWithoutConstructor();

        Expect::that(static fn(): mixed => $driver->stop())
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );

        $collecting = new \ReflectionProperty(XdebugDriver::class, 'collecting');
        $collecting->setValue($driver, true);

        Expect::that(static fn() => $driver->start())
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is already open. Call stop() before start().',
            );
    }

    #[Test]
    public function collectsRealLineCoverageOverTheFixture(): void
    {
        if (!XdebugDriver::isAvailable()) {
            // This integration test requires Xdebug with "coverage" in its mode.
            // The test cannot change this environment property.
            throw new SkipTest('xdebug with coverage mode is not available');
        }

        $fixtureFile = (string) new \ReflectionClass(Adder::class)->getFileName();
        $fixtureDir = \dirname($fixtureFile);

        $driver = new XdebugDriver();
        $driver->start();
        $sum = new Adder()->add(19, 23);
        $raw = $driver->stop();

        $map = CoverageMap::fromRaw($raw, new PathFilter([$fixtureDir]));
        $file = $map->files()[$fixtureFile] ?? null;

        Expect::that($sum)->because('collects real line coverage over the fixture')->toBe(42)
            ->and($file)->not()->toBeNull();
        \assert($file !== null);

        Expect::that($file->coveredLines)->because('collects real line coverage over the fixture')->toContain(Adder::ADD_RETURN_LINE)
            ->and($file->uncoveredLines)->not()->toContain(Adder::ADD_RETURN_LINE);
    }

    /**
     * @return list<string>
     */
    private function activeXdebugModes(): array
    {
        if (\function_exists('xdebug_info')) {
            $modes = \xdebug_info('mode');

            if (\is_array($modes)) {
                return \array_values(\array_filter($modes, \is_string(...)));
            }
        }

        $ini = \ini_get('xdebug.mode');

        return \is_string($ini) && $ini !== ''
            ? \array_map(\trim(...), \explode(',', $ini))
            : [];
    }
}
