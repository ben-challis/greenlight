<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Coverage\PathFilter;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\SkipTest;
use Greenlight\Tests\Fixture\Coverage\Adder;

final class XdebugDriverTest
{
    #[Test]
    public function collectsRealLineCoverageOverTheFixture(): void
    {
        if (!XdebugDriver::isAvailable()) {
            // This integration test needs xdebug running with "coverage" in
            // its mode, an environment property the test cannot change.
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

        Expect::that($sum)->toBe(42)
            ->and($file)->not()->toBeNull();
        \assert($file !== null);

        Expect::that(\in_array(Adder::ADD_RETURN_LINE, $file->coveredLines, true))->toBeTrue()
            ->and(\in_array(Adder::ADD_RETURN_LINE, $file->uncoveredLines, true))->toBeFalse();
    }
}
