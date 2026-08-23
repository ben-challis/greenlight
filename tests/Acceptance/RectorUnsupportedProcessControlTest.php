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
final readonly class RectorUnsupportedProcessControlTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesUnsupportedProcessControlsUntouched(): void
    {
        $cases = [];

        foreach (RectorMigrationRunTest::unsupportedProcessControlSources() as $caseName => [$source]) {
            $cases[$caseName] = $source;
        }

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-process-controls');

        foreach ($probes as $caseName => $probe) {
            Expect::that($probe->changed)->because('unsupported process control case: ' . $caseName)->toBeFalse();
            Expect::that($probe->code)->because('unsupported process control case: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
