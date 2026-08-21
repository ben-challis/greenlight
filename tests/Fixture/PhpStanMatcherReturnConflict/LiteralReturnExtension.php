<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PhpStanMatcherReturnConflict;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\ExpectationExtension;

final class LiteralReturnExtension implements ExpectationExtension, Fake
{
    /** @return array{toBeAvailable: \Closure(string): true} */
    #[\Override]
    public function matchers(): array
    {
        return [
            'toBeAvailable' => static fn(string $subject): true => true,
        ];
    }
}
