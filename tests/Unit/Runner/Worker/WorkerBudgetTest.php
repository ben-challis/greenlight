<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Worker\WorkerBudget;

final readonly class WorkerBudgetTest
{
    #[Test]
    public function countBudgetExhaustionUsesAnInclusiveBoundary(): void
    {
        $disabled = new WorkerBudget();
        $limited = new WorkerBudget(maxTests: 3);

        Expect::that($disabled->exhaustedByCount(\PHP_INT_MAX))
            ->because('an unset test-count budget MUST remain disabled')
            ->toBeFalse()
            ->and($limited->exhaustedByCount(2))
            ->because('the worker MUST continue below its test-count budget')
            ->toBeFalse()
            ->and($limited->exhaustedByCount(3))
            ->because('the worker MUST recycle when it reaches its test-count budget')
            ->toBeTrue()
            ->and($limited->exhaustedByCount(4))
            ->because('the test-count budget MUST remain exhausted above its boundary')
            ->toBeTrue();
    }

    #[Test]
    #[DataSet('invalidBudgets')]
    public function rejectsInvalidBudgets(?int $maxTests, ?int $maxMemoryBytes, string $message): void
    {
        Expect::that(static fn(): WorkerBudget => new WorkerBudget($maxTests, $maxMemoryBytes))
            ->because('worker recycle budgets MUST be positive when set')
            ->toThrow(\InvalidArgumentException::class, message: $message);
    }

    /**
     * @return iterable<string, array{?int, ?int, string}>
     */
    public static function invalidBudgets(): iterable
    {
        yield 'zero test budget' => [0, null, 'The worker test budget must be at least 1.'];
        yield 'negative test budget' => [-1, null, 'The worker test budget must be at least 1.'];
        yield 'zero memory budget' => [null, 0, 'The worker memory budget must be at least 1 byte.'];
        yield 'negative memory budget' => [null, -1, 'The worker memory budget must be at least 1 byte.'];
    }
}
