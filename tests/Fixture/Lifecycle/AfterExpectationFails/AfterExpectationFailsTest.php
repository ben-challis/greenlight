<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\AfterExpectationFails;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Test;

use Greenlight\Test\SkipTest;

use function Greenlight\expect;

final class AfterExpectationFailsTest
{
    #[Test]
    public function passesUntilTeardown(): void {}

    #[Test]
    public function skipsBeforeTeardown(): never
    {
        throw new SkipTest('not applicable');
    }

    #[Test]
    public function failsBeforeTeardown(): void
    {
        expect('body actual')->toBe('body expected');
    }

    #[After]
    public function verifies(): void
    {
        expect('actual')->toBe('expected');
    }
}
