<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanMatcherReturn;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class InvalidReturnExtension implements ExpectationExtension, Fake
{
    #[\Override]
    public function matchers(): array
    {
        return [
            'toReturnText' => static fn(string $subject): string => $subject,
            'toReturnBoolean' => static fn(string $subject): bool => $subject !== '',
            'toReturnMixed' => static fn(string $subject): mixed => $subject !== '',
            'toReturnUntyped' => static fn(string $subject) => $subject !== '',
        ];
    }
}
