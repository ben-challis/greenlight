<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose;

use Greenlight\Doubles\Fake;

use Greenlight\Harness\Disposable;

use function Greenlight\expect;

final class VerifyingProbe implements Disposable, Fake
{
    public int $touches = 0;

    public function touch(): void
    {
        ++$this->touches;
    }

    #[\Override]
    public function dispose(): void
    {
        expect($this->touches)->toBe(2);
    }
}
