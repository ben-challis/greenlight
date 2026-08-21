<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\AllowParallel;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[AllowParallel]
#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedClassShapeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    #[DataSet(RectorMigrationRunTest::class, 'unsupportedClassShapeSources')]
    public function leavesClassesWithUnsupportedApiUntouched(string $source): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, $source, name: 'unsupported');

        Expect::that($probe->changed)->toBeFalse();
        Expect::that($probe->code)->toBe($source);
    }
}
