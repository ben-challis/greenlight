<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
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
}
