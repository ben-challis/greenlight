<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\DeadlineExceededError;
use Greenlight\Test\OperationCancelledError;

final class OperationCancelledErrorTest
{
    #[Test]
    public function findsDeadlineCancellationThroughNestedWrappers(): void
    {
        $deadline = DeadlineExceededError::forTest();
        $wrapper = new \RuntimeException('Outer wrapper.', previous: new \LogicException('Inner wrapper.', previous: $deadline));

        Expect::that(OperationCancelledError::find($wrapper))->toBe($deadline);
        Expect::that(DeadlineExceededError::find($wrapper))->toBe($deadline);
        Expect::that($deadline->testDeadline)->toBeTrue();
    }

    #[Test]
    public function deadlineLookupExcludesOtherOperationCancellation(): void
    {
        $scope = new class ('The operation scope ended.') extends OperationCancelledError {};
        $wrapper = new \RuntimeException('Outer wrapper.', previous: $scope);

        Expect::that(OperationCancelledError::find($wrapper))->toBe($scope);
        Expect::that(DeadlineExceededError::find($wrapper))->toBeNull();
        Expect::that(OperationCancelledError::find(new \RuntimeException('Ordinary failure.')))->toBeNull();
    }

    #[Test]
    public function temporalDeadlineKeepsItsOwnCauseAndDiagnostic(): void
    {
        $deadline = DeadlineExceededError::forTemporal();

        Expect::that($deadline->testDeadline)->toBeFalse();
        Expect::that($deadline->getMessage())->toBe('The temporal expectation time limit stopped an asynchronous operation.');
    }
}
