<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

use Greenlight\Expect\ExpectationExtension;

final class PositiveNumbersExtension implements ExpectationExtension
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBePositive' => static fn(mixed $subject): bool => \is_int($subject) && $subject > 0,
        ];
    }
}
