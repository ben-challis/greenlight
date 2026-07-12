<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\VerifyOnDispose;

use Greenlight\Expect\Expect;
use Greenlight\Harness\Disposable;

final class VerifyingProbe implements Disposable
{
    public int $touches = 0;

    public function touch(): void
    {
        ++$this->touches;
    }

    #[\Override]
    public function dispose(): void
    {
        Expect::that($this->touches)->toBe(2);
    }
}
