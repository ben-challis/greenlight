<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Coverage\Collection\PathFilter;
use Greenlight\Coverage\CoverageError;
use Greenlight\Expect\Expect;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Fixture\Coverage\Adder;
use Greenlight\Tests\Fixture\Coverage\FakeXdebugRuntime;
use Greenlight\Tests\Support\ClassFile;

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

        Expect::that(static fn(): mixed => $driver->stop())
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );

        Expect::that($runtime->calls)
            ->because('an invalid stop MUST NOT use the Xdebug runtime')
            ->toBe([]);

        $driver->start();

        Expect::that(static fn() => $driver->start())
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is already open. Call stop() before start().',
            );

        $driver->stop();

        Expect::that($runtime->calls)
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

        Expect::that($coverage->lines)
            ->because('Xdebug collection MUST return the extension line data')
            ->toBe([
                '/src/Example.php' => [
                    10 => 1,
                    11 => -1,
                ],
            ]);

        Expect::that($runtime->flags)
            ->because('Xdebug collection MUST request unused and dead code analysis')
            ->toBe(\XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE);

        Expect::that($runtime->calls)
            ->because('Xdebug collection MUST read and stop the extension before closing its window')
            ->toBe(['start', 'collect', 'stop']);

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a completed Xdebug collection MUST close its window')
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );
    }

    #[Test]
    #[Isolated]
    public function branchCollectionRequiresCapabilityAndAddsOnlyTheBranchFlag(): void
    {
        if (!\defined('XDEBUG_CC_UNUSED')) {
            \define('XDEBUG_CC_UNUSED', 1);
        }

        if (!\defined('XDEBUG_CC_DEAD_CODE')) {
            \define('XDEBUG_CC_DEAD_CODE', 2);
        }

        if (!\defined('XDEBUG_CC_BRANCH_CHECK')) {
            \define('XDEBUG_CC_BRANCH_CHECK', 4);
        }

        $unsupported = new FakeXdebugRuntime();
        $unsupported->branchCoverage = false;

        Expect::that(static fn(): XdebugDriver => new XdebugDriver($unsupported, branchCoverage: true))
            ->because('branch capability MUST be checked before collection starts')
            ->toThrow(
                CoverageError::class,
                message: 'Branch coverage requires Xdebug branch support: Install an Xdebug version that defines XDEBUG_CC_BRANCH_CHECK.',
            );

        $runtime = new FakeXdebugRuntime();
        $runtime->coverage = ['file.php' => ['lines' => [], 'functions' => []]];
        $driver = new XdebugDriver($runtime, branchCoverage: true);
        $driver->start();
        $coverage = $driver->stop();

        Expect::that($runtime->flags)
            ->because('ordinary Xdebug remains line-only and opt-in adds the branch flag')
            ->toBe(\XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE | \XDEBUG_CC_BRANCH_CHECK);
        Expect::that($coverage->branchCoverage)->toBeTrue();
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

        Expect::that($sum)
            ->because('collects real line coverage over the fixture')
            ->toBe(42);

        Expect::that($file)
            ->because('collects real line coverage over the fixture')
            ->not()
            ->toBeNull();

        Expect::that($file->coveredLines)
            ->because('collects real line coverage over the fixture')
            ->toContain(Adder::ADD_RETURN_LINE);

        Expect::that($file->uncoveredLines)
            ->because('collects real line coverage over the fixture')
            ->not()
            ->toContain(Adder::ADD_RETURN_LINE);
    }

    #[Test]
    public function collectsRealBranchCoverageOverTheFixtureWhenXdebugSupportsIt(): void
    {
        if (!XdebugDriver::isBranchCoverageAvailable()) {
            throw new SkipTest('xdebug branch coverage is not available');
        }

        $fixtureFile = ClassFile::of(Adder::class);
        $driver = new XdebugDriver(branchCoverage: true);
        $driver->start();
        $sum = new Adder()->add(19, 23);
        $map = $driver->stop()->toMap(new PathFilter([\dirname($fixtureFile)]));

        Expect::that($sum)->toBe(42);
        Expect::that($map->branchCoverage)->toBeTrue();
        Expect::that($map->branchTotal())
            ->because('an Xdebug branch run MUST retain function opcode data')
            ->toBeGreaterThan(0);
        Expect::that($map->pathTotal())
            ->because('an Xdebug branch run MUST retain function path data')
            ->toBeGreaterThan(0);
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
