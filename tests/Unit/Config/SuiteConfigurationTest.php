<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Expect\Expect;

final class SuiteConfigurationTest
{
    /**
     * @param array<mixed> $paths
     * @param array<mixed> $tags
     */
    #[Test]
    #[DataSet('invalidSuites')]
    public function rejectsAnInvalidSuite(
        string $name,
        array $paths,
        array $tags,
        string $message,
    ): void {
        Expect::that(static fn(): SuiteConfiguration => new SuiteConfiguration($name, $paths, $tags))
            ->because('a suite configuration MUST satisfy its documented value domains')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    #[Test]
    public function preservesZeroStringsInEverySuiteField(): void
    {
        $suite = new SuiteConfiguration('0', ['0'], ['0']);

        Expect::that($suite->name)
            ->because('a zero-string suite name is not empty')
            ->toBe('0')
            ->and($suite->paths)
            ->because('a zero-string suite path is not empty')
            ->toBe(['0'])
            ->and($suite->tags)
            ->because('a zero-string suite tag is not empty')
            ->toBe(['0']);
    }

    /**
     * @return iterable<string, array{string, array<mixed>, array<mixed>, string}>
     */
    public static function invalidSuites(): iterable
    {
        yield 'empty name' => [
            '',
            ['tests'],
            [],
            'Suite names cannot be empty.',
        ];
        yield 'paths are not a list' => [
            'unit',
            ['source' => 'tests'],
            [],
            'Suite "unit" paths must be a list.',
        ];
        yield 'path is not a string' => [
            'unit',
            [42],
            [],
            'Suite "unit" was given a path that is not a string.',
        ];
        yield 'empty path' => [
            'unit',
            [''],
            [],
            'Suite "unit" was given an empty path.',
        ];
        yield 'no paths' => [
            'unit',
            [],
            [],
            'Suite "unit" has no paths. Call in() with at least one directory inside its configurator.',
        ];
        yield 'tags are not a list' => [
            'unit',
            ['tests'],
            ['kind' => 'fast'],
            'Suite "unit" tags must be a list.',
        ];
        yield 'tag is not a string' => [
            'unit',
            ['tests'],
            [42],
            'Suite "unit" was given a tag that is not a string.',
        ];
        yield 'empty tag' => [
            'unit',
            ['tests'],
            [''],
            'Suite "unit" was given an empty tag.',
        ];
    }
}
