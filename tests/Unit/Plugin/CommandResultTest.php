<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Plugin\CommandResult;

use function Greenlight\expect;

final readonly class CommandResultTest
{
    #[Test]
    public function rejectsAnInvalidSignal(): void
    {
        expect()->calling(static fn(): CommandResult => CommandResult::interrupted(0))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a signal number from 1 through 127.',
            );

        expect()->calling(static fn(): CommandResult => CommandResult::interrupted(128))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Use a signal number from 1 through 127.',
            );
    }
}
