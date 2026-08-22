<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expect;

final class GreenlightConfigWorkerValidationTest
{
    /**
     * @param \Closure(): void $configure
     */
    #[Test]
    #[DataSet('invalidWorkerConfigurations')]
    public function invalidWorkerConfigurationsGiveExactGuidance(\Closure $configure, string $message): void
    {
        Expect::that($configure)
            ->because('each invalid worker option MUST identify the required fix')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{\Closure(): void, non-empty-string}>
     */
    public static function invalidWorkerConfigurations(): iterable
    {
        yield 'negative worker count' => [
            static function (): void {
                GreenlightConfig::create()->workers(count: -3); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Worker count must be at least 1, got -3.',
        ];

        yield 'unsupported worker count string' => [
            static function (): void {
                new \ReflectionMethod(GreenlightConfig::class, 'workers')
                    ->invoke(GreenlightConfig::create(), 'several');
            },
            'Worker count must be a positive integer or "auto", got "several".',
        ];

        yield 'negative recycle limit' => [
            static function (): void {
                GreenlightConfig::create()->workers(recycleAfterTests: -4); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'recycleAfterTests must be at least 1, got -4.',
        ];
    }
}
