<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
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
    public function rejectedVariadicCallsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = new SuiteBuilder('integration')
            ->in('tests/Existing')
            ->tag('existing');

        Expect::that(static fn(): SuiteBuilder => $builder->in('tests/Added', '')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a rejected path call does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        Expect::that(static fn(): SuiteBuilder => $builder->tag('added', '')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a rejected tag call does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        $configuration = $builder->toConfiguration();

        Expect::that($configuration->paths)
            ->because('a rejected path call retains the prior paths')
            ->toBe(['tests/Existing']);
        Expect::that($configuration->tags)
            ->because('a rejected tag call retains the prior tags')
            ->toBe(['existing']);
    }

    #[Test]
    public function rejectsNullBytesAtTheBuilderBoundary(): void
    {
        $builder = new SuiteBuilder('integration');

        Expect::that(static fn(): SuiteBuilder => $builder->in("tests/Integration\0nested"))
            ->because('suite paths MUST be valid filesystem inputs')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Suite "integration" paths cannot contain a null byte.',
            );
    }

    /**
     * @param list<non-empty-string> $paths
     * @param list<non-empty-string> $tags
     */
    #[Test]
    #[DataSet('zeroStringValues')]
    public function preservesZeroStringsInBuiltConfiguration(array $paths, array $tags): void
    {
        $suite = new SuiteBuilder('suite')
            ->in(...$paths)
            ->tag(...$tags)
            ->toConfiguration();

        Expect::that($suite->paths)
            ->because('a zero-string suite path is not empty')
            ->toBe($paths);
        Expect::that($suite->tags)
            ->because('a zero-string suite tag is not empty')
            ->toBe($tags);
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
