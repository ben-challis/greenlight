<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\WatchConfiguration;
use Greenlight\Expect\Expect;

final readonly class WatchConfigurationTest
{
    #[Test]
    #[DataSet('nonPositiveDebounces')]
    public function rejectsANonPositiveDebounce(int $milliseconds): void
    {
        Expect::that(static fn(): WatchConfiguration => new WatchConfiguration($milliseconds))
            ->because('a watch configuration MUST have a positive debounce')
            ->toThrow(
                InvalidConfiguration::class,
                message: \sprintf(
                    'The watch debounce must be at least 1 millisecond, got %d.',
                    $milliseconds,
                ),
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveDebounces(): iterable
    {
        yield 'zero' => [0];

        yield 'negative' => [-1];
    }
}
