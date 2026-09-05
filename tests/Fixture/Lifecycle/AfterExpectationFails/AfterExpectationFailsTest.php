<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\AfterExpectationFails;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\SkipTest;

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
        Expect::that('body actual')->toBe('body expected');
    }

    #[After]
    public function verifies(): void
    {
        Expect::that('actual')->toBe('expected');
    }
}
