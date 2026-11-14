<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\Attempt;

use Greenlight\Harness\Disposable;

final class NoisyDisposable implements Disposable
{
    public function use(): void {}

    #[\Override]
    public function dispose(): void
    {
        echo 'disposal output;';
    }
}
