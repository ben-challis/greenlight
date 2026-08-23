<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\Cleanup;
use Greenlight\Test\CleanupFailed;

final readonly class CleanupTest
{
    #[Test]
    public function closeRunsCallbacksOnceInReverseRegistrationOrder(): void
    {
        $cleanup = new Cleanup();
        $trace = [];
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'first';
        });
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'second';
        });

        $cleanup->close();
        $cleanup->close();

        Expect::that($trace)
            ->because('cleanup callbacks run once in reverse registration order')
            ->toBe(['second', 'first']);
    }

    #[Test]
    public function closeReportsEveryFailureAfterAllCallbacksRun(): void
    {
        $cleanup = new Cleanup();
        $trace = [];
        $firstFailure = new \RuntimeException('first cleanup broke');
        $secondFailure = new \LogicException('second cleanup broke');
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'last';
        });
        $cleanup->defer(static function () use (&$trace, $secondFailure): never {
            $trace[] = 'second failure';

            throw $secondFailure;
        });
        $cleanup->defer(static function () use (&$trace, $firstFailure): never {
            $trace[] = 'first failure';

            throw $firstFailure;
        });
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'first';
        });

        Expect::that(static fn() => $cleanup->close())
            ->because('callback failures MUST NOT prevent later cleanup')
            ->toThrow(
                static function (CleanupFailed $cleanupFailed) use ($firstFailure, $secondFailure, &$trace): void {
                    Expect::that($trace)->toBe(['first', 'first failure', 'second failure', 'last']);
                    Expect::that($cleanupFailed->failures)->toBe([$firstFailure, $secondFailure]);
                    Expect::that($cleanupFailed->getPrevious())->toBeNull();
                },
            );
    }

    #[Test]
    public function closeIgnoresCallbackReturnValues(): void
    {
        $cleanup = new Cleanup();
        $trace = [];
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'void';
        });
        $cleanup->defer(static function () use (&$trace): string {
            $trace[] = 'value';

            return 'ignored';
        });

        $cleanup->close();

        Expect::that($trace)->toBe(['value', 'void']);
    }

    #[Test]
    public function deferRejectsRegistrationAfterCleanupStarts(): void
    {
        $cleanup = new Cleanup();
        $cleanup->close();

        Expect::that(static fn() => $cleanup->defer(static function (): void {}))
            ->because('a closed cleanup stack cannot accept callbacks')
            ->toThrow(
                \LogicException::class,
                message: 'Cleanup cannot be registered after test cleanup starts.',
            );
    }
}
