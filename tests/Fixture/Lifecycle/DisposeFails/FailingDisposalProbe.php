<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\DisposeFails;

use Greenlight\Harness\Disposable;

final class FailingDisposalProbe implements Disposable
{
    public function touch(): void {}

    #[\Override]
    public function dispose(): never
    {
        throw new \RuntimeException('disposal broke');
    }
}
