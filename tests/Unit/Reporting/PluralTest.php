<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Reporting\Plural;

use function Greenlight\expect;

final class PluralTest
{
    #[Test]
    public function countPluralizesRegularNouns(): void
    {
        expect(Plural::count(1, 'test'))->because('count pluralizes regular nouns')->toBe('1 test');
        expect(Plural::count(2, 'test'))->toBe('2 tests');
        expect(Plural::count(0, 'expectation'))->toBe('0 expectations');
        expect(Plural::count(1, 'expectation'))->toBe('1 expectation');
        expect(Plural::count(11, 'worker'))->toBe('11 workers');
    }

    #[Test]
    public function countUsesTheIrregularPluralWhenGiven(): void
    {
        expect(Plural::count(1, 'class', 'classes'))->because('count uses the irregular plural when given')->toBe('1 class');
        expect(Plural::count(3, 'class', 'classes'))->toBe('3 classes');
    }
}
