<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\RectorProbe;

#[RequiresResource('analysis-process')]
final readonly class RectorUnsupportedClassShapeTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    #[DataSet(RectorMigrationRunTest::class, 'unsupportedClassShapeSources')]
    public function leavesClassesWithUnsupportedApiUntouched(string $source): void
    {
        $probe = RectorProbe::convert($this->tempDirectory, $source, name: 'unsupported');

        Expect::that($probe->changed)->toBeFalse()
            ->and($probe->code)->toBe($source);
    }
}
