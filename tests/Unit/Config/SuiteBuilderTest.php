<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
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

    /**
     * @param list<string> $paths
     * @param list<string> $tags
     */
    #[Test]
    #[DataSet('zeroStringValues')]
    public function preservesZeroStringsInBuiltConfiguration(array $paths, array $tags): void
    {
        $suite = new SuiteBuilder('suite')
            ->in(...$paths)
            ->tag(...$tags)
            ->toConfiguration();

        Expect::that([$suite->paths, $suite->tags])
            ->because('zero-string suite builder values are not empty')
            ->toBe([$paths, $tags]);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function zeroStringValues(): iterable
    {
        yield 'path' => [['0'], ['fast']];
        yield 'tag' => [['tests'], ['0']];
    }
}
