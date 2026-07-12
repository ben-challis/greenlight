<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose;

use Greenlight\Attribute\Test;

final readonly class VerifyOnDisposeTest
{
    public function __construct(private VerifyingProbe $probe) {}

    #[Test]
    public function touchesOnceButTwoAreExpected(): void
    {
        $this->probe->touch();
    }
}
