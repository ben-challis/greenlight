<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\RetryPolicy;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\RetryPlugin;

final class RetryPluginInheritanceTest
{
    #[Test]
    public function throwableFilterAcceptsSubclassesOfTheConfiguredType(): void
    {
        $policy = new RetryPolicy(1, \LogicException::class);
        $result = new TestResult(
            new TestId('Example\RetryTest', 'retries'),
            Outcome::Errored,
            0.1,
            0,
        );

        Expect::that(new RetryPlugin()->shouldRetry(
            $policy,
            $result,
            1,
            new \InvalidArgumentException('retry'),
        ))
            ->because('a retry filter MUST accept subclasses of its configured throwable type')
            ->toBeTrue();
    }
}
