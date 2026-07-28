<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Config\Configuration;
use Greenlight\Config\WatchConfiguration;
use Greenlight\Config\WorkerCount;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Discovery\Filter;
use Greenlight\Expect\Expect;
use Greenlight\Runner\SelectionFilter;

final class SelectionFilterTest
{
    #[Test]
    public function mapsEveryConfiguredSelectionDimension(): void
    {
        $configuration = new Configuration(
            paths: ['tests'],
            suites: [],
            workers: WorkerCount::auto(),
            recycleAfterTests: null,
            recycleAboveMemoryBytes: 256 * 1024 * 1024,
            coverage: null,
            watch: new WatchConfiguration(),
            plugins: [],
            policy: new ResultPolicy(),
            stopAfterFailures: null,
            randomizeOrder: false,
            randomSeed: null,
            groups: ['focus'],
            filters: ['::search*'],
            onlyTests: ['Acme\\PaymentsTest::exact'],
            excludeGroups: ['quarantined'],
            excludeClasses: ['Legacy*'],
            excludeMethods: ['draft*'],
            excludePaths: ['/repo/vendor'],
        );

        Expect::that(SelectionFilter::fromConfiguration($configuration))
            ->because('the shared selection bridge MUST preserve every configured selector')
            ->toEqual(new Filter(
                includeGroups: ['focus'],
                excludeGroups: ['quarantined'],
                excludeClasses: ['Legacy*'],
                excludeMethods: ['draft*'],
                excludePaths: ['/repo/vendor'],
                includeIds: ['::search*'],
                includeExactIds: ['Acme\\PaymentsTest::exact'],
            ));
    }
}
