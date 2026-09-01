<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Command;

use Greenlight\Attribute\Test;
use Greenlight\Command\CommandResult;
use Greenlight\Expect\Expect;

final readonly class CommandResultTest
{
    #[Test]
    public function identifiesSuccess(): void
    {
        Expect::that(CommandResult::success()->isSuccessful())->toBeTrue();
        Expect::that(CommandResult::failure()->isSuccessful())->toBeFalse();
        Expect::that(CommandResult::usage()->isSuccessful())->toBeFalse();
    }

    #[Test]
    public function identifiesUsageErrors(): void
    {
        Expect::that(CommandResult::usage()->isUsageError())->toBeTrue();
        Expect::that(CommandResult::failure()->isUsageError())->toBeFalse();
    }

    #[Test]
    public function containsAnInterruptionSignal(): void
    {
        Expect::that(CommandResult::interrupted(\SIGTERM)->interruptionSignal())->toBe(\SIGTERM);
        Expect::that(CommandResult::failure()->interruptionSignal())->toBeNull();
    }

    #[Test]
    public function rejectsAnInvalidSignal(): void
    {
        Expect::that(static fn(): CommandResult => CommandResult::interrupted(0))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Signal number MUST be from 1 through 127.',
            );

        Expect::that(static fn(): CommandResult => CommandResult::interrupted(128))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Signal number MUST be from 1 through 127.',
            );
    }
}
