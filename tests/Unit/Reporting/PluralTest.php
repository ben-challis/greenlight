<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Plural;

final class PluralTest
{
    #[Test]
    public function countPluralizesRegularNouns(): void
    {
        Expect::that(Plural::count(1, 'test'))->because('count pluralizes regular nouns')->toBe('1 test');
        Expect::that(Plural::count(2, 'test'))->toBe('2 tests');
        Expect::that(Plural::count(0, 'expectation'))->toBe('0 expectations');
        Expect::that(Plural::count(1, 'expectation'))->toBe('1 expectation');
        Expect::that(Plural::count(11, 'worker'))->toBe('11 workers');
    }

    #[Test]
    public function countUsesTheIrregularPluralWhenGiven(): void
    {
        Expect::that(Plural::count(1, 'class', 'classes'))->because('count uses the irregular plural when given')->toBe('1 class');
        Expect::that(Plural::count(3, 'class', 'classes'))->toBe('3 classes');
    }
}
