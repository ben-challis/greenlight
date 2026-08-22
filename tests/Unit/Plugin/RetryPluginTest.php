<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\RetryPlugin;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\TestId;

final class RetryPluginTest
{
    /**
     * @param positive-int|null $times
     * @param class-string<\Throwable>|null $onlyOn
     * @param positive-int $attempt
     */
    #[Test]
    #[DataSet('retryDecisions')]
    public function throwableFiltersAndAttemptLimitsControlRetries(
        ?int $times,
        ?string $onlyOn,
        int $attempt,
        ?\Throwable $cause,
        bool $expected,
    ): void {
        $plugin = new RetryPlugin();
        $result = new TestResult(
            new TestId('Example\RetryTest', 'retries'),
            Outcome::Errored,
            0.1,
            0,
        );
        $policy = new RetryPolicy($times, $onlyOn);

        Expect::that($plugin->shouldRetry(
            $policy,
            $result,
            $attempt,
            $cause,
        ))
            ->because('retry decisions MUST honor throwable filters and attempt limits')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{
     *     positive-int|null,
     *     class-string<\Throwable>|null,
     *     positive-int,
     *     \Throwable|null,
     *     bool
     * }>
     */
    public static function retryDecisions(): iterable
    {
        yield 'matching throwable before limit' => [
            2,
            \DomainException::class,
            1,
            new \DomainException('retry'),
            true,
        ];

        yield 'matching throwable at limit' => [
            2,
            \DomainException::class,
            2,
            new \DomainException('last retry'),
            true,
        ];

        yield 'matching throwable after limit' => [
            2,
            \DomainException::class,
            3,
            new \DomainException('exhausted'),
            false,
        ];

        yield 'non-matching throwable' => [
            2,
            \DomainException::class,
            1,
            new \RuntimeException('wrong type'),
            false,
        ];

        yield 'missing cause with throwable filter' => [
            2,
            \DomainException::class,
            1,
            null,
            false,
        ];

        yield 'no retry metadata' => [
            null,
            null,
            1,
            new \DomainException('ignored'),
            false,
        ];
    }
}
