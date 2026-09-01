<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Output\ExitCode;
use Greenlight\Expect\Expect;

final readonly class ExitCodeTest
{
    #[Test]
    #[DataSet('processCodes')]
    public function convertsCommandResultsAtTheProcessSeam(ExitCode $exitCode, int $processCode): void
    {
        Expect::that($exitCode->toInt())->toBe($processCode);
        Expect::that(ExitCode::fromInt($processCode))->toBe($exitCode);
    }

    /** @return iterable<string, array{ExitCode, int}> */
    public static function processCodes(): iterable
    {
        yield 'success' => [ExitCode::Success, 0];
        yield 'failure' => [ExitCode::Failure, 1];
        yield 'usage' => [ExitCode::Usage, 64];
        yield 'interrupted' => [ExitCode::Interrupted, 130];
        yield 'terminated' => [ExitCode::Terminated, 143];
    }

    #[Test]
    public function rejectsAnUnknownProcessCode(): void
    {
        Expect::that(static fn(): ExitCode => ExitCode::fromInt(7))
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Exit code 7 does not identify a Greenlight command result.',
            );
    }
}
