<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanMatcherReturnConflict;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class BooleanReturnExtension implements ExpectationExtension, Fake
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeAvailable' => static fn(string $subject): bool => $subject !== '',
        ];
    }
}
