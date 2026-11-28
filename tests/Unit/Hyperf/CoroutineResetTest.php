<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Hyperf;

use Greenlight\Attribute\Test;
use Greenlight\Hyperf\CoroutineReset;

use function Greenlight\expect;

final readonly class CoroutineResetTest
{
    #[Test]
    public function aLateCallFromAnAbandonedCoroutineDoesNotResetAgain(): void
    {
        $calls = 0;
        $reset = new CoroutineReset(static function () use (&$calls): void {
            ++$calls;
        });

        $reset->run();
        $reset->run();

        expect($calls)->toBe(1);
    }

    #[Test]
    public function aFailedResetDoesNotRunAgain(): void
    {
        $calls = 0;
        $reset = new CoroutineReset(static function () use (&$calls): never {
            ++$calls;

            throw new \RuntimeException('The reset failed.');
        });

        expect()->calling($reset->run(...))->toThrow(\RuntimeException::class);
        $reset->run();

        expect($calls)->toBe(1);
    }
}
