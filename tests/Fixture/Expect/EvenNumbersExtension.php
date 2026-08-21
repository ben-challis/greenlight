<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class EvenNumbersExtension implements ExpectationExtension, Fake
{
    /** @return array{toBeEven: \Closure(mixed): bool} */
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeEven' => static fn(mixed $subject): bool => \is_int($subject) && $subject % 2 === 0,
        ];
    }
}
