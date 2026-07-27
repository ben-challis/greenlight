<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\PerTestDisposeFails;

use Greenlight\Doubles\Fake;
use Greenlight\Harness\Disposable;

final class FailingPerTestDisposal implements Disposable, Fake
{
    public function touch(): void {}

    #[\Override]
    public function dispose(): never
    {
        throw new \RuntimeException('per-test disposal broke');
    }
}
