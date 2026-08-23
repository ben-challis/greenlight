<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\WatchBuilder;
use Greenlight\Expect\Expect;

final class WatchBuilderTest
{
    #[Test]
    public function buildsTheDefaultAndConfiguredDebounce(): void
    {
        $default = new WatchBuilder()->toConfiguration();
        $configured = new WatchBuilder()->debounceMilliseconds(750)->toConfiguration();

        Expect::that($default->debounceMilliseconds)
            ->because('watch mode MUST have a stable default debounce')
            ->toBe(200);

        Expect::that($configured->debounceMilliseconds)
            ->because('the configured debounce MUST reach watch mode')
            ->toBe(750);
    }

    #[Test]
    public function accumulatesWatchInputsPatternsAndAFileLimit(): void
    {
        $configuration = new WatchBuilder()
            ->paths('templates', 'config/app.yaml')
            ->paths('migrations')
            ->include('**/*.twig', '**/*.yaml')
            ->include('**/*.sql')
            ->exclude('build/**', 'var/greenlight/**')
            ->maximumFiles(500)
            ->toConfiguration();

        Expect::that($configuration->paths)->toBe(['templates', 'config/app.yaml', 'migrations']);
        Expect::that($configuration->includePatterns)->toBe(['**/*.twig', '**/*.yaml', '**/*.sql']);
        Expect::that($configuration->excludePatterns)->toBe(['build/**', 'var/greenlight/**']);
        Expect::that($configuration->maximumFiles)->toBe(500);
    }

    #[Test]
    public function rejectsAnEmptyWatchPath(): void
    {
        Expect::that(static fn(): WatchBuilder => new WatchBuilder()->paths(''))
            ->toThrow(InvalidConfiguration::class, message: 'Watch paths cannot contain an empty string.');
    }

    #[Test]
    public function rejectsAnEmptyIncludePattern(): void
    {
        Expect::that(static fn(): WatchBuilder => new WatchBuilder()->include(''))
            ->toThrow(InvalidConfiguration::class, message: 'Watch include patterns cannot contain an empty string.');
    }

    #[Test]
    public function rejectsAnEmptyExcludePattern(): void
    {
        Expect::that(static fn(): WatchBuilder => new WatchBuilder()->exclude(''))
            ->toThrow(InvalidConfiguration::class, message: 'Watch exclude patterns cannot contain an empty string.');
    }

    #[Test]
    public function rejectsANullByteInAWatchPath(): void
    {
        Expect::that(static fn(): WatchBuilder => new WatchBuilder()->paths("templates\0private"))
            ->toThrow(InvalidConfiguration::class, message: 'Watch paths cannot contain a null byte.');
    }

    #[Test]
    public function rejectsANonPositiveFileLimit(): void
    {
        Expect::that(static fn(): WatchBuilder => new WatchBuilder()->maximumFiles(0))
            ->toThrow(InvalidConfiguration::class, message: 'The watch file limit must be at least 1, got 0.');
    }

    #[Test]
    #[DataSet('nonPositiveDebounces')]
    public function theDebounceMustBePositive(int $milliseconds): void
    {
        Expect::that(static function () use ($milliseconds): void {
            new WatchBuilder()->debounceMilliseconds($milliseconds); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        })
            ->because('the watch debounce must be positive')
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
