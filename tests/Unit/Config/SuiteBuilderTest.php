<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\SuiteBuilder;
use Greenlight\Expect\Expect;

final class SuiteBuilderTest
{
    #[Test]
    public function repeatedCallsAccumulatePathsAndTagsInDeclarationOrder(): void
    {
        $suite = new SuiteBuilder('integration')
            ->in('tests/Integration')
            ->in('tests/Browser', 'tests/Smoke')
            ->tag('io')
            ->tag('slow', 'external')
            ->toConfiguration();

        Expect::that($suite->paths)
            ->because('repeated in() calls append each path')
            ->toBe(['tests/Integration', 'tests/Browser', 'tests/Smoke']);
        Expect::that($suite->tags)
            ->because('repeated tag() calls append each tag')
            ->toBe(['io', 'slow', 'external']);
    }

    #[Test]
    public function rejectedCallsDoNotRetainEarlierArguments(): void
    {
        $builder = new SuiteBuilder('integration')
            ->in('tests/Base')
            ->tag('base');

        Expect::that(static fn(): SuiteBuilder => $builder->in('tests/Partial', ''))
            ->because('a rejected path list does not partially change the suite')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Suite "integration" was given an empty path.',
            );
        Expect::that(static fn(): SuiteBuilder => $builder->tag('partial', ''))
            ->because('a rejected tag list does not partially change the suite')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Suite "integration" was given an empty tag.',
            );

        $suite = $builder->toConfiguration();

        Expect::that($suite->paths)
            ->because('rejected calls do not retain earlier arguments')
            ->toBe(['tests/Base'])
            ->and($suite->tags)
            ->toBe(['base']);
    }
}
