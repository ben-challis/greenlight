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
    public function keepsStableDefaults(): void
    {
        $configuration = new WatchConfiguration();

        Expect::value($configuration->debounceMilliseconds)->toBe(200);
        Expect::value($configuration->paths)->toBe([]);
        Expect::value($configuration->includePatterns)->toBe([]);
        Expect::value($configuration->excludePatterns)->toBe([]);
        Expect::value($configuration->maximumFiles)->toBe(100_000);
    }

    #[Test]
    #[DataSet('nonPositiveDebounces')]
    public function rejectsANonPositiveDebounce(int $milliseconds): void
    {
        Expect::calling(static fn(): WatchConfiguration => new WatchConfiguration($milliseconds))
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

    #[Test]
    public function rejectsMalformedInternalLists(): void
    {
        Expect::calling(static fn(): WatchConfiguration => new WatchConfiguration(paths: ['valid', 7]))
            ->toThrow(InvalidConfiguration::class, message: 'Watch paths must contain non-empty strings.');

        Expect::calling(static fn(): WatchConfiguration => new WatchConfiguration(includePatterns: ['key' => '*.yaml']))
            ->toThrow(InvalidConfiguration::class, message: 'Watch include patterns must be a list.');
    }

    #[Test]
    public function rejectsANonPositiveFileLimit(): void
    {
        Expect::calling(static fn(): WatchConfiguration => new WatchConfiguration(maximumFiles: 0))
            ->toThrow(InvalidConfiguration::class, message: 'The watch file limit must be at least 1, got 0.');
    }
}
