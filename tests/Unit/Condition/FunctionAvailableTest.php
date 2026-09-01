<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Condition;

use Greenlight\Attribute\Test;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Expect\Expect;

final readonly class FunctionAvailableTest
{
    #[Test]
    public function rejectsAnEmptyFunctionName(): void
    {
        Expect::that(static fn(): FunctionAvailable => new FunctionAvailable('')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a function availability condition MUST identify the function')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Function name MUST NOT be empty.',
            );
    }
}
