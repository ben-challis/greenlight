<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\Test;
use Greenlight\Config\Configuration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Runner\SelectionFilter;

final class SelectionFilterTest
{
    #[Test]
    public function configurationSelectionDimensionsControlAcceptedTests(): void
    {
        $filter = SelectionFilter::fromConfiguration($this->configuration());

        Expect::that($filter->accepts(
            'Acme\FastTest',
            'works',
            ['fast'],
            '/project/tests/FastTest.php',
        ))
            ->because('a test that satisfies every configured selection dimension MUST be accepted')
            ->toBeTrue();
        Expect::that($filter->accepts(
            'Acme\FastTest',
            'works',
            ['slow'],
            '/project/tests/FastTest.php',
        ))
            ->because('the configured include group MUST restrict selection')
            ->toBeFalse();
        Expect::that($filter->accepts(
            'Acme\FastTest',
            'works',
            ['fast', 'quarantined'],
            '/project/tests/FastTest.php',
        ))
            ->because('the configured exclude group MUST override the include group')
            ->toBeFalse();
        Expect::that($filter->accepts(
            'Acme\LegacyTest',
            'works',
            ['fast'],
            '/project/tests/LegacyTest.php',
        ))
            ->because('the configured class exclusion MUST restrict selection')
            ->toBeFalse();
        Expect::that($filter->accepts(
            'Acme\FastTest',
            'manualCheck',
            ['fast'],
            '/project/tests/FastTest.php',
        ))
            ->because('the configured method exclusion MUST restrict selection')
            ->toBeFalse();
        Expect::that($filter->accepts(
            'Acme\FastTest',
            'works',
            ['fast'],
            '/vendor/tests/FastTest.php',
        ))
            ->because('the configured path exclusion MUST restrict selection')
            ->toBeFalse();
        Expect::that($filter->acceptsId('Acme\FastTest::worksNow'))
            ->because('the configured test ID pattern MUST select matching IDs')
            ->toBeTrue();
        Expect::that($filter->acceptsId('Acme\ExactTest::only'))
            ->because('the configured exact test ID MUST select that complete ID')
            ->toBeTrue();
        Expect::that($filter->acceptsId('Acme\FastTest::other'))
            ->because('configured test IDs MUST reject an ID that matches neither form')
            ->toBeFalse();
    }

    private function configuration(): Configuration
    {
        $base = GreenlightConfig::create()->build();

        return new Configuration(
            paths: $base->paths,
            suites: $base->suites,
            workers: $base->workers,
            recycleAfterTests: $base->recycleAfterTests,
            recycleAboveMemoryBytes: $base->recycleAboveMemoryBytes,
            coverage: $base->coverage,
            watch: $base->watch,
            plugins: $base->plugins,
            policy: $base->policy,
            stopAfterFailures: $base->stopAfterFailures,
            randomizeOrder: $base->randomizeOrder,
            randomSeed: $base->randomSeed,
            groups: ['fast'],
            filters: ['*::works*'],
            onlyTests: ['Acme\ExactTest::only'],
            excludeGroups: ['quarantined'],
            excludeClasses: ['Legacy'],
            excludeMethods: ['manual'],
            excludePaths: ['/vendor/tests'],
        );
    }
}
