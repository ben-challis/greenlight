<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedAssertionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesClassesWithUnsupportedAssertionsUntouched(): void
    {
        $cases = [];

        foreach (RectorMigrationRunTest::unsupportedAssertionSources() as $caseName => [$source]) {
            $cases[$caseName] = $source;
        }

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-assertions');

        foreach ($probes as $caseName => $probe) {
            Expect::that($probe->changed)->because('unsupported assertion case: ' . $caseName)->toBeFalse();
            Expect::that($probe->code)->because('unsupported assertion case: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
