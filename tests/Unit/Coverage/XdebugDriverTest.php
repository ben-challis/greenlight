<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Coverage\PathFilter;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\Adder;

final class XdebugDriverTest
{
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
}
