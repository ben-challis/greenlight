<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

use function Greenlight\expect;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedFrameworkApiTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function leavesClassesWithUnsupportedFrameworkApisUntouched(): void
    {
        $cases = [];

        foreach (RectorMigrationRunTest::unsupportedFrameworkApiSources() as $caseName => [$source]) {
            $cases[$caseName] = $source;
        }

        $probes = RectorProbe::convertBatch($this->tempDirectory, $cases, name: 'unsupported-framework-apis');

        foreach ($probes as $caseName => $probe) {
            expect($probe->changed)->because('unsupported framework API case: ' . $caseName)->toBeFalse();
            expect($probe->code)->because('unsupported framework API case: ' . $caseName)->toBe($cases[$caseName]);
        }
    }
}
