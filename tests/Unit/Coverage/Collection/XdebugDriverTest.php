<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Coverage\Collection\PathFilter;
use Greenlight\Coverage\CoverageError;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Fixture\Coverage\Adder;
use Greenlight\Tests\Fixture\Coverage\FakeXdebugRuntime;
use Greenlight\Tests\Support\ClassFile;

use function Greenlight\expect;

final class XdebugDriverTest
{
    #[Test]
    public function availabilityMatchesTheActiveModesAndMissingCoverageModeIsActionable(): void
    {
        $available = \extension_loaded('xdebug')
            && \in_array('coverage', $this->activeXdebugModes(), true);

        expect(XdebugDriver::isAvailable())
            ->because('Xdebug availability matches the active extension modes')
            ->toBe($available);

        if ($available) {
            expect(new XdebugDriver())
                ->because('an active Xdebug coverage mode permits driver construction')
                ->toBeInstanceOf(XdebugDriver::class);

            return;
        }

        expect()->calling(static fn(): XdebugDriver => new XdebugDriver())
            ->because('an inactive Xdebug coverage mode gives exact configuration guidance')
            ->toThrow(
                CoverageError::class,
                message: 'Coverage driver "xdebug" is not available. Enable the Xdebug extension. '
                . 'Add "coverage" to xdebug.mode or the XDEBUG_MODE environment variable.',
            );
    }

    #[Test]
    #[Isolated]
    public function reportsInvalidCollectionStateExactly(): void
    {
        if (!\defined('XDEBUG_CC_UNUSED')) {
            \define('XDEBUG_CC_UNUSED', 1);
        }

        if (!\defined('XDEBUG_CC_DEAD_CODE')) {
            \define('XDEBUG_CC_DEAD_CODE', 2);
        }

        $runtime = new FakeXdebugRuntime();
        $driver = new XdebugDriver($runtime);

        expect()->calling(static fn(): mixed => $driver->stop())
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );

        expect($runtime->calls)
            ->because('an invalid stop MUST NOT use the Xdebug runtime')
            ->toBe([]);

        $driver->start();

        expect()->calling(static fn() => $driver->start())
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is already open. Call stop() before start().',
            );

        $driver->stop();

        expect($runtime->calls)
            ->because('an invalid start MUST NOT open a second collection window')
            ->toBe(['start', 'collect', 'stop']);
    }

    #[Test]
    #[Isolated]
    public function collectionLifecycleUsesAllLineFlagsAndClosesTheWindow(): void
    {
        if (!\defined('XDEBUG_CC_UNUSED')) {
            \define('XDEBUG_CC_UNUSED', 1);
        }

        if (!\defined('XDEBUG_CC_DEAD_CODE')) {
            \define('XDEBUG_CC_DEAD_CODE', 2);
        }

        $runtime = new FakeXdebugRuntime();
        $driver = new XdebugDriver($runtime);
        $driver->start();
        $coverage = $driver->stop();

        expect($coverage->lines)
            ->because('Xdebug collection MUST return the extension line data')
            ->toBe([
                '/src/Example.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);

        expect($runtime->flags)
            ->because('Xdebug collection MUST request unused and dead code analysis')
            ->toBe(\XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE);

        expect($runtime->calls)
            ->because('Xdebug collection MUST read and stop the extension before closing its window')
            ->toBe(['start', 'collect', 'stop']);

        expect()->calling(static fn(): mixed => $driver->stop())
            ->because('a completed Xdebug collection MUST close its window')
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
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

        $fixtureFile = ClassFile::of(Adder::class);
        $fixtureDir = \dirname($fixtureFile);

        $driver = new XdebugDriver();
        $driver->start();
        $sum = new Adder()->add(19, 23);
        $raw = $driver->stop();

        $map = $raw->toMap(new PathFilter([$fixtureDir]));
        $file = $map->files()[$fixtureFile] ?? null;

        expect($sum)
            ->because('collects real line coverage over the fixture')
            ->toBe(42);

        expect($file)
            ->because('collects real line coverage over the fixture')
            ->not()
            ->toBeNull();

        expect($file->coveredLines)
            ->because('collects real line coverage over the fixture')
            ->toContain(Adder::ADD_RETURN_LINE);

        expect($file->uncoveredLines)
            ->because('collects real line coverage over the fixture')
            ->not()
            ->toContain(Adder::ADD_RETURN_LINE);
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
