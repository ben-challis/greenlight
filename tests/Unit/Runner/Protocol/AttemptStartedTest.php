<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Protocol\Messages\AttemptStarted;
use Greenlight\Test\TestId;

final class AttemptStartedTest
{
    #[Test]
    #[DataSet('nonPositiveAttempts')]
    public function directMessagesRejectNonPositiveAttempts(int $attempt): void
    {
        $id = new TestId('Example\RetryTest', 'retries');

        Expect::that(static fn(): AttemptStarted => new AttemptStarted($id, $attempt))
            ->because('attempt-started messages MUST identify a positive attempt number')
            ->toThrow(
                \InvalidArgumentException::class,
                message: \sprintf('Attempt numbers MUST be positive. Actual value: %d.', $attempt),
            );
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonPositiveAttempts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-4];
    }
}
