<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\CommandResult;

final readonly class CommandResultTest
{
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
