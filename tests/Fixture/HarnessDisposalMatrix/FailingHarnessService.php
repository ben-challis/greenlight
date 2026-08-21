<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\HarnessDisposalMatrix;

use Greenlight\Doubles\Fake;
use Greenlight\Harness\Disposable;

final readonly class FailingHarnessService implements Disposable, Fake
{
    public function use(): void {}

    #[\Override]
    public function dispose(): never
    {
        throw new \RuntimeException('harness service disposal broke');
    }
}
