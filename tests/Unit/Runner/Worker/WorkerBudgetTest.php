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
