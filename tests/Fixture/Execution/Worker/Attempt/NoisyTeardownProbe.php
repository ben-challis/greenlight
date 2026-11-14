<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\Attempt;

use Greenlight\Test\Cleanup;
use Greenlight\Test\ExpectationCounter;

final readonly class NoisyTeardownProbe
{
    public function __construct(
        private Cleanup $cleanup,
        private NoisyDisposable $disposable,
    ) {}

    public function passes(): void
    {
        $this->disposable->use();
        ExpectationCounter::increment();
        $this->cleanup->defer(static function (): void {
            echo 'cleanup output;';
            \trigger_error('cleanup warning', \E_USER_WARNING);
        });
    }
}
