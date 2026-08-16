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
    #[DataSet('nonPositiveDebounces')]
    public function theDebounceMustBePositive(int $milliseconds): void
    {
        Expect::that(static function () use ($milliseconds): void {
            new WatchBuilder()->debounceMilliseconds($milliseconds);
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
