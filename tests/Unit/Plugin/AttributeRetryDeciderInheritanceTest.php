<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Plugin\AttributeRetryDecider;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\RetryPolicy;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final class AttributeRetryDeciderInheritanceTest
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

        expect(new AttributeRetryDecider()->shouldRetry(
            $policy,
            $result,
            1,
            new \InvalidArgumentException('retry'),
        ))
            ->because('a retry filter MUST accept subclasses of its configured throwable type')
            ->toBeTrue();
    }
}
