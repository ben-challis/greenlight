<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Expect\Expect;

final class PredicateMatcherStrictReturnTest
{
    #[Test]
    public function truthyPredicateResultsDoNotMatch(): void
    {
        $matcher = Argument::predicate(static fn(): int => 1, 'truthy result');

        Expect::that($matcher->matches('value'))
            ->because('an argument predicate MUST return the boolean value true to match')
            ->toBeFalse();
    }
}
