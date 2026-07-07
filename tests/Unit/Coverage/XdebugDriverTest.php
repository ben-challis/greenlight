<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
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
            // Deliberate no-op: this integration test needs xdebug running
            // with "coverage" in its mode, which is an environment property
            // the test cannot change. The bootstrap runner has no skip
            // mechanism, so an unavailable driver passes vacuously here and
            // the behaviour is exercised on environments that have it.
            return;
        }

        $fixtureFile = (string) new \ReflectionClass(Adder::class)->getFileName();
        $fixtureDir = \dirname($fixtureFile);

        $driver = new XdebugDriver();
        $driver->start();
        $sum = new Adder()->add(19, 23);
        $raw = $driver->stop();

        $map = CoverageMap::fromRaw($raw, new PathFilter([$fixtureDir]));
        $file = $map->files()[$fixtureFile] ?? null;

        new Expect()->that($sum)->toBe(42)
            ->and($file)->not()->toBeNull();
        \assert($file !== null);

        new Expect()->that(\in_array(Adder::ADD_RETURN_LINE, $file->coveredLines, true))->toBeTrue()
            ->and(\in_array(Adder::ADD_RETURN_LINE, $file->uncoveredLines, true))->toBeFalse();
    }
}
