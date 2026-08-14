<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\Configuration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;

final class ConfigurationCopyTest
{
    /**
     * @param \Closure(Configuration): Configuration $mutate
     */
    #[Test]
    #[DataSet('selectionMutationOrders')]
    public function selectionMutationsPreserveEachOthersState(\Closure $mutate): void
    {
        $configuration = $mutate(GreenlightConfig::create()->build());

        Expect::that($configuration->onlyTests)
            ->because('a selection mutation MUST preserve earlier test ID selections')
            ->toBe(['App\ExampleTest::runs']);
        Expect::that($configuration->excludePaths)
            ->because('a selection mutation MUST preserve earlier path exclusions')
            ->toBe(['/project/generated']);
    }

    /**
     * @return iterable<string, array{\Closure(Configuration): Configuration}>
     */
    public static function selectionMutationOrders(): iterable
    {
        yield 'only tests then excluded paths' => [
            static fn(Configuration $configuration): Configuration => $configuration
                ->withOnlyTests(['App\ExampleTest::runs'])
                ->withExcludePaths(['/project/generated']),
        ];

        yield 'excluded paths then only tests' => [
            static fn(Configuration $configuration): Configuration => $configuration
                ->withExcludePaths(['/project/generated'])
                ->withOnlyTests(['App\ExampleTest::runs']),
        ];
    }
}
