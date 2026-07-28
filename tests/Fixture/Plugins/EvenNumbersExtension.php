<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final readonly class EvenNumbersExtension implements ExpectationExtension, Fake
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeEven' => static fn(mixed $subject): bool => \is_int($subject) && $subject % 2 === 0,
        ];
    }
}
