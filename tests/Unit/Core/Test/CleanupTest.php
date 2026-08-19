<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core\Test;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Expect\Expect;

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

        $firstFailures = $cleanup->close();
        $secondFailures = $cleanup->close();

        Expect::that($trace)
            ->because('cleanup callbacks run once in reverse registration order')
            ->toBe(['second', 'first']);
        Expect::that($firstFailures)->toBe([]);
        Expect::that($secondFailures)->toBe([]);
    }

    #[Test]
    public function closeContinuesAfterACallbackFailure(): void
    {
        $cleanup = new Cleanup();
        $trace = [];
        $failure = new \RuntimeException('cleanup broke');
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'last';
        });
        $cleanup->defer(static function () use (&$trace, $failure): never {
            $trace[] = 'failure';

            throw $failure;
        });
        $cleanup->defer(static function () use (&$trace): void {
            $trace[] = 'first';
        });

        $failures = $cleanup->close();

        Expect::that($trace)
            ->because('a callback failure MUST NOT prevent later cleanup')
            ->toBe(['first', 'failure', 'last']);
        Expect::that($failures)->toBe([$failure]);
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
