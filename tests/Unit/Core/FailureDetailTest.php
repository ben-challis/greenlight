<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Expect\Expect;

final readonly class FailureDetailTest
{
    #[Test]
    public function rejectsAnEmptyMessage(): void
    {
        Expect::that(static fn(): FailureDetail => new FailureDetail(''))
            ->because('a failure detail MUST explain the failure')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Failure detail message must not be empty.',
            );
    }
}
