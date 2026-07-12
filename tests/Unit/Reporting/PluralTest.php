<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Plural;

final class PluralTest
{
    #[Test]
    public function countPluralisesRegularNouns(): void
    {
        Expect::that(Plural::count(1, 'test'))->toBe('1 test')
            ->and(Plural::count(2, 'test'))->toBe('2 tests')
            ->and(Plural::count(0, 'expectation'))->toBe('0 expectations')
            ->and(Plural::count(1, 'expectation'))->toBe('1 expectation')
            ->and(Plural::count(11, 'worker'))->toBe('11 workers');
    }

    #[Test]
    public function countUsesTheIrregularPluralWhenGiven(): void
    {
        Expect::that(Plural::count(1, 'class', 'classes'))->toBe('1 class')
            ->and(Plural::count(3, 'class', 'classes'))->toBe('3 classes');
    }
}
